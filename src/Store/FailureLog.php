<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use RuntimeException;
use SQLite3Exception;
use Throwable;

/**
 * @internal
 */
class FailureLog
{
    /**
     * The name of the file beside the store that records each store failure.
     */
    public const FILE = 'failures.jsonl';

    /**
     * The most lines the file keeps.
     */
    public const LINES = 100;

    /**
     * The mode of the failure file.
     */
    protected const FILE_MODE = 0600;

    /**
     * Whether this process has reported a failure.
     */
    protected bool $reported = false;

    /**
     * Create a new failure log instance.
     */
    public function __construct(protected Configuration $configuration)
    {
        //
    }

    /**
     * Record a dropped batch beside the store and report the first failure of the process.
     */
    public function record(Throwable $exception, int $dropped): void
    {
        $this->log(FailureEntry::BATCH, $exception, "could not store a batch of {$dropped} records", $dropped);
    }

    /**
     * Record a failed pruning pass beside the store and report the first failure of the process.
     */
    public function pruneFailed(Throwable $exception): void
    {
        $this->log(FailureEntry::PRUNE, $exception, 'could not prune the store');
    }

    /**
     * Record a damaged store the writer replaced; its damage may read as a file that is not a database.
     */
    public function recovered(Throwable $exception): void
    {
        try {
            $this->append($this->line(FailureEntry::RECOVERY, $exception, FailureKind::CORRUPT));
        } catch (Throwable) {
            //
        }
    }

    /**
     * Read the dropped batches the file still holds, oldest first.
     *
     * @return list<array{at: float, kind: string, dropped: int, message: string}>
     */
    public function dropped(): array
    {
        $dropped = [];

        foreach ($this->entries(FailureEntry::BATCH) as $entry) {
            if (($entry['dropped'] ?? 0) > 0) {
                $dropped[] = [
                    'at' => $entry['at'],
                    'kind' => $entry['kind'],
                    'dropped' => $entry['dropped'],
                    'message' => $entry['message'],
                ];
            }
        }

        return $dropped;
    }

    /**
     * Read the failed pruning passes the file still holds, oldest first.
     *
     * @return list<array{at: float, kind: string, message: string}>
     */
    public function prunes(): array
    {
        return array_map(fn (array $entry) => [
            'at' => $entry['at'],
            'kind' => $entry['kind'],
            'message' => $entry['message'],
        ], $this->entries(FailureEntry::PRUNE));
    }

    /**
     * Empty the file under its lock, keeping the file, and say whether there was one.
     */
    public function clear(): bool
    {
        $path = dirname($this->configuration->database).'/'.static::FILE;

        if (! is_file($path) || ($handle = @fopen($path, 'r+')) === false) {
            return false;
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Record a failure beside the store and report the first of the process.
     */
    protected function log(FailureEntry $entry, Throwable $exception, string $failed, ?int $dropped = null): void
    {
        try {
            $this->append($this->line($entry, $exception, FailureKind::of($exception), $dropped));
        } catch (Throwable) {
            //
        }

        try {
            $this->report($exception, $failed);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Read the well-formed lines of the given entry the file still holds, oldest first.
     *
     * @return list<array{at: float, kind: string, message: string, dropped: int|null}>
     */
    protected function entries(FailureEntry $entry): array
    {
        $lines = @file(dirname($this->configuration->database).'/'.static::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];

        foreach ($lines ?: [] as $line) {
            $fields = json_decode($line, associative: true);

            if (is_array($fields) && ($fields['entry'] ?? null) === $entry->value && is_numeric($fields['at'] ?? null) && is_string($fields['kind'] ?? null)) {
                $entries[] = [
                    'at' => (float) $fields['at'],
                    'kind' => $fields['kind'],
                    'message' => is_string($fields['message'] ?? null) ? $fields['message'] : '',
                    'dropped' => is_int($fields['dropped'] ?? null) ? $fields['dropped'] : null,
                ];
            }
        }

        return $entries;
    }

    /**
     * Get the line that records a failure, with the records it dropped when it dropped a batch.
     */
    protected function line(FailureEntry $entry, Throwable $exception, FailureKind $kind, ?int $dropped = null): string
    {
        return json_encode([
            'at' => Instant::now(),
            'entry' => $entry->value,
            'kind' => $kind->value,
            'code' => $exception instanceof SQLite3Exception ? $exception->getCode() : null,
            'message' => $exception->getMessage(),
            ...($dropped === null ? [] : ['dropped' => $dropped]),
        ], RecordMapper::JSON_FLAGS);
    }

    /**
     * Append a line to the failure file under an exclusive lock, keeping only its latest lines.
     */
    protected function append(string $line): void
    {
        $path = dirname($this->configuration->database).'/'.static::FILE;
        $created = ! file_exists($path);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Firewatch could not open [{$path}].");
        }

        try {
            if ($created) {
                chmod($path, static::FILE_MODE);
            }

            // A lock held elsewhere loses this line rather than holding up the request.
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException("Firewatch could not lock [{$path}].");
            }

            $lines = preg_split('/\R/', (string) stream_get_contents($handle), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            // Below the cap the line is appended, so a failed write never loses the earlier lines.
            if (count($lines) < self::LINES) {
                fwrite($handle, $line."\n");
            } else {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode("\n", [...array_slice($lines, 1 - self::LINES), $line])."\n");
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Report the process's first failure through the exception handler.
     */
    protected function report(Throwable $exception, string $failed): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;

        report(new RuntimeException("Firewatch {$failed}: {$exception->getMessage()}", previous: $exception));
    }
}
