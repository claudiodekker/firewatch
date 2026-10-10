<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Holdings;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use SQLite3;

/**
 * @internal
 */
class StoreChecks
{
    /**
     * The hours without a captured record after which a store that holds records is a warning.
     */
    public const QUIET_HOURS = 24;

    /**
     * The most messages the integrity check asks SQLite for.
     */
    public const INTEGRITY_MESSAGES = 20;

    /**
     * The bits of a file mode that are permissions.
     */
    protected const PERMISSION_BITS = 0777;

    /**
     * Create a new store checks instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected FailureLog $failures,
        protected string $osFamily = PHP_OS_FAMILY,
    ) {
        //
    }

    /**
     * Report the modes of the store directory and file against 0700 and 0600.
     */
    public function permissions(): CheckResult
    {
        $directory = $this->directory();
        $file = $this->configuration->database;

        if ($this->osFamily === 'Windows') {
            return CheckResult::info(__('firewatch::messages.doctor.store-permissions.windows'));
        }

        if (! is_dir($directory)) {
            return CheckResult::info(__('firewatch::messages.doctor.store-permissions.absent'));
        }

        $looser = $this->isLooser($directory, Writer::DIRECTORY_MODE) || (is_file($file) && $this->isLooser($file, Writer::FILE_MODE));

        if (! $looser) {
            return CheckResult::ok(__('firewatch::messages.doctor.store-permissions.ok'));
        }

        return CheckResult::warn(
            __('firewatch::messages.doctor.store-permissions.looser', [
                'directory_mode' => $this->mode($directory),
                'file_mode' => is_file($file) ? $this->mode($file) : __('firewatch::messages.doctor.none'),
            ]),
            __('firewatch::messages.doctor.store-permissions.looser_fix', [
                'directory' => $directory,
                'path' => $file,
            ]),
        );
    }

    /**
     * Report the store directory's own ignore file.
     */
    public function gitignore(): CheckResult
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            return CheckResult::info(__('firewatch::messages.doctor.store-gitignore.absent'));
        }

        if (! is_file($directory.'/.gitignore')) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.store-gitignore.missing', ['directory' => $directory]),
                __('firewatch::messages.doctor.store-gitignore.missing_fix', ['directory' => $directory]),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.store-gitignore.ok', ['directory' => $directory]));
    }

    /**
     * Report whose file the store is and its schema version.
     */
    public function identity(): CheckResult
    {
        $path = $this->configuration->database;

        try {
            $this->reader->ensureUsable();
        } catch (StoreUnusable $unusable) {
            return match ($unusable->state) {
                StoreState::ABSENT => CheckResult::info(__('firewatch::messages.doctor.store-identity.absent', ['path' => $path])),
                StoreState::FOREIGN => CheckResult::fail(
                    __('firewatch::messages.doctor.store-identity.foreign', ['path' => $path]),
                    __('firewatch::messages.doctor.store-identity.foreign_fix'),
                ),
                StoreState::SCHEMA_MISMATCH => $this->mismatch((int) $unusable->found),
                // The file is Firewatch's, so store-integrity owns the failure.
                StoreState::CORRUPT => CheckResult::info(__('firewatch::messages.doctor.store-identity.damaged')),
                StoreState::BUSY, StoreState::UNAVAILABLE => $this->notRead($unusable),
            };
        }

        return CheckResult::ok(__('firewatch::messages.doctor.store-identity.ok', [
            'path' => $path,
            'version' => Schema::VERSION,
        ]));
    }

    /**
     * Report what `PRAGMA quick_check` finds, failing on any message but "ok".
     */
    public function integrity(): CheckResult
    {
        try {
            $messages = $this->reader->snapshot(fn (SQLite3 $connection) => $this->quickCheck($connection));
        } catch (StoreUnusable $unusable) {
            return $unusable->state === StoreState::CORRUPT ? $this->damaged() : $this->notRead($unusable);
        }

        if ($messages === ['ok']) {
            return CheckResult::ok(__('firewatch::messages.doctor.store-integrity.ok'));
        }

        return CheckResult::fail(
            __('firewatch::messages.doctor.store-integrity.problems', [
                'problems' => trans_choice('firewatch::messages.doctor.store-integrity.problem_count', count($messages), ['count' => count($messages)]),
                'first' => $messages[0] ?? '',
            ]),
            __('firewatch::messages.doctor.store-integrity.damaged_fix'),
        );
    }

    /**
     * Report what the store holds, its span, sizes, retention, last prune and per-type coverage start.
     */
    public function activity(): CheckResult
    {
        try {
            [$holdings, $markers] = $this->reader->snapshot(fn (SQLite3 $connection) => [Holdings::read($connection), Markers::read($connection)]);
        } catch (StoreUnusable $unusable) {
            return $this->notRead($unusable);
        }

        $retention = [
            'age' => $this->configuration->retentionAge,
            'limit' => number_format($this->configuration->retentionRecords),
            'busy_timeout' => $this->configuration->busyTimeoutMilliseconds,
        ];

        if ($holdings->records === 0) {
            return CheckResult::info(__('firewatch::messages.doctor.store-activity.empty', $retention));
        }

        $facts = [
            'records' => $this->records($holdings->records),
            'oldest' => $this->moment($holdings->oldest),
            'newest' => $this->moment($holdings->newest),
            'file' => Number::fileSize((int) @filesize($this->configuration->database), precision: 1),
            'live' => Number::fileSize($holdings->liveBytes, precision: 1),
            'prune' => $this->moment($markers->pruneClaimedAt),
            'coverage' => $this->coverage($holdings, $markers),
            ...$retention,
        ];

        if ($holdings->newest !== null && $holdings->newest < Instant::now() - static::QUIET_HOURS * 3600) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.store-activity.quiet', $facts),
                __('firewatch::messages.doctor.store-activity.quiet_fix'),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.store-activity.ok', $facts));
    }

    /**
     * Report the dropped batches the failure file holds, quoting the newest.
     */
    public function losses(): CheckResult
    {
        $dropped = $this->failures->dropped();

        if ($dropped === []) {
            return CheckResult::ok(__('firewatch::messages.doctor.store-losses.ok'));
        }

        $newest = $dropped[count($dropped) - 1];

        return CheckResult::warn(
            __('firewatch::messages.doctor.store-losses.dropped', [
                'batches' => trans_choice('firewatch::messages.doctor.store-losses.batches', count($dropped), ['count' => count($dropped)]),
                'records' => $this->records(array_sum(array_column($dropped, 'dropped'))),
                'at' => $this->moment($newest['at']),
                'kind' => $newest['kind'],
                'message' => $newest['message'],
            ]),
            match (FailureKind::tryFrom($newest['kind'])) {
                FailureKind::BUSY => __('firewatch::messages.doctor.store-losses.busy_fix', ['milliseconds' => $this->configuration->busyTimeoutMilliseconds]),
                FailureKind::FULL => __('firewatch::messages.doctor.store-losses.full_fix'),
                default => __('firewatch::messages.doctor.store-losses.other_fix'),
            },
        );
    }

    /**
     * Report the drift rows by kind, type and count.
     */
    public function drift(): CheckResult
    {
        try {
            $drift = $this->reader->snapshot(fn (SQLite3 $connection) => Holdings::read($connection)->drift);
        } catch (StoreUnusable $unusable) {
            return $this->notRead($unusable);
        }

        if ($drift === []) {
            return CheckResult::ok(__('firewatch::messages.doctor.store-drift.ok'));
        }

        $rows = array_map(
            fn (array $row) => "{$row['kind']} ".($row['type'] === '' ? __('firewatch::messages.doctor.store-drift.store') : $row['type'])." ({$row['count']})",
            $drift,
        );

        return CheckResult::warn(
            __('firewatch::messages.doctor.store-drift.found', ['rows' => implode(', ', $rows)]),
            __('firewatch::messages.doctor.store-drift.found_fix', ['line' => NightwatchInstall::VERIFIED_LINE]),
        );
    }

    /**
     * Get the store directory.
     */
    protected function directory(): string
    {
        return dirname($this->configuration->database);
    }

    /**
     * Determine if a path grants a permission that the mode does not.
     */
    protected function isLooser(string $path, int $expected): bool
    {
        clearstatcache(true, $path);

        return ((int) fileperms($path) & static::PERMISSION_BITS & ~$expected) !== 0;
    }

    /**
     * Get the permissions of a path as four octal digits.
     */
    protected function mode(string $path): string
    {
        return sprintf('%04o', (int) fileperms($path) & static::PERMISSION_BITS);
    }

    /**
     * Get the result for a store of another schema version, which a writer rebuilds only when it is the older one.
     */
    protected function mismatch(int $found): CheckResult
    {
        $facts = [
            'found' => $found,
            'expected' => Schema::VERSION,
        ];

        if ($found < Schema::VERSION) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.store-identity.older', $facts),
                __('firewatch::messages.doctor.store-identity.older_fix'),
            );
        }

        return CheckResult::warn(
            __('firewatch::messages.doctor.store-identity.newer', $facts),
            __('firewatch::messages.doctor.store-identity.newer_fix'),
        );
    }

    /**
     * Get the failure for a store file that SQLite can't read.
     */
    protected function damaged(): CheckResult
    {
        return CheckResult::fail(
            __('firewatch::messages.doctor.store-integrity.damaged'),
            __('firewatch::messages.doctor.store-integrity.damaged_fix'),
        );
    }

    /**
     * Get what a check that reads the store reports when the store can't be read for a reason another check owns.
     */
    protected function notRead(StoreUnusable $unusable): CheckResult
    {
        return match ($unusable->state) {
            StoreState::ABSENT => CheckResult::info(__('firewatch::messages.doctor.store.absent')),
            StoreState::BUSY => CheckResult::warn(
                __('firewatch::messages.doctor.store.busy'),
                __('firewatch::messages.doctor.store.busy_fix'),
            ),
            // The sqlite check owns the failure.
            StoreState::UNAVAILABLE => CheckResult::info(__('firewatch::messages.doctor.store.unavailable')),
            StoreState::FOREIGN, StoreState::SCHEMA_MISMATCH => CheckResult::info(__('firewatch::messages.doctor.store.see_identity')),
            StoreState::CORRUPT => CheckResult::info(__('firewatch::messages.doctor.store.see_integrity')),
        };
    }

    /**
     * Get the messages of `PRAGMA quick_check`, at most the cap.
     *
     * @return list<string>
     */
    protected function quickCheck(SQLite3 $connection): array
    {
        $rows = Stored::rows($connection, 'PRAGMA quick_check('.static::INTEGRITY_MESSAGES.')');

        return array_map(fn (array $row) => (string) reset($row), $rows);
    }

    /**
     * Get the types whose history starts later than the store's creation, each with the instant and the reason.
     */
    protected function coverage(Holdings $holdings, Markers $markers): string
    {
        $coverage = '';

        foreach ($holdings->result($markers)['types'] as $row) {
            if ($row['complete_reason'] === null || $row['complete_reason'] === 'created') {
                continue;
            }

            $coverage .= __('firewatch::messages.doctor.store-activity.coverage', [
                'type' => $row['type'],
                'from' => $this->moment($row['complete_from_at']),
                'reason' => $row['complete_reason'],
            ]);
        }

        return $coverage;
    }

    /**
     * Get a count of records in its grammatical number.
     */
    protected function records(int $count): string
    {
        return trans_choice('firewatch::messages.doctor.store-activity.records', $count, ['count' => number_format($count)]);
    }

    /**
     * Get an instant as local time with its zone, or "never" for none or for the zero a store that never pruned holds.
     */
    protected function moment(?float $epoch): string
    {
        if ($epoch === null || $epoch === 0.0) {
            return __('firewatch::messages.doctor.store-activity.never');
        }

        return Carbon::createFromTimestamp($epoch, date_default_timezone_get())->format('Y-m-d H:i:s T');
    }
}
