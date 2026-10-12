<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Notices;
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
     * The longest a failure waits for the file while another process writes its own line, in milliseconds, before its line is lost.
     */
    protected const BUSY_TIMEOUT_MILLISECONDS = 50;

    /**
     * The mode of the failure file.
     */
    protected const FILE_MODE = 0600;

    /**
     * Whether a notice about a failure has been written.
     */
    protected bool $notified = false;

    /**
     * The path of the failure file.
     */
    protected string $path;

    /**
     * Create a new failure log instance.
     */
    public function __construct(Configuration $configuration, protected Notices $notices)
    {
        $this->path = dirname($configuration->database).'/'.static::FILE;
    }

    /**
     * Record a dropped batch beside the store and write a notice about the first failure.
     */
    public function record(Throwable $exception, int $dropped): void
    {
        $this->log(FailureEntry::BATCH, $exception, "could not store a batch of {$dropped} records", $dropped);
    }

    /**
     * Record a failed pruning pass beside the store and write a notice about the first failure.
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
            $this->append(FailureEntry::RECOVERY, $exception, FailureKind::CORRUPT);
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
            if ($entry['dropped'] > 0) {
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
     * Get the instant of the oldest line once the file holds its most lines, or null while it holds every failure.
     */
    public function completeFrom(): ?float
    {
        $lines = $this->lines();

        if (count($lines) < self::LINES) {
            return null;
        }

        $readable = array_filter(array_map($this->parse(...), $lines));

        return min([INF, ...array_column($readable, 'at')]);
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
     * Empty the file under its lock, keeping the file.
     */
    public function clear(): void
    {
        if (! is_file($this->path) || ($handle = @fopen($this->path, 'r+')) === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Record a failure beside the store and write a notice about the first one.
     */
    protected function log(FailureEntry $entry, Throwable $exception, string $failed, ?int $dropped = null): void
    {
        try {
            $this->append($entry, $exception, FailureKind::of($exception), $dropped);
        } catch (Throwable) {
            //
        }

        try {
            $this->notify($exception, $failed);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Read the well-formed lines of the given entry the file still holds, oldest first.
     *
     * @return list<array{at: float, entry: string, kind: string, message: string, dropped: int}>
     */
    protected function entries(FailureEntry $entry): array
    {
        $parsed = array_map($this->parse(...), $this->lines());

        return array_values(array_filter($parsed, fn (?array $fields) => $fields !== null && $fields['entry'] === $entry->value));
    }

    /**
     * Read the lines the file holds, oldest first.
     *
     * @return list<string>
     */
    protected function lines(): array
    {
        return @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /**
     * Read a line of the file, or null when it is not well formed.
     *
     * @return array{at: float, entry: string, kind: string, message: string, dropped: int}|null
     */
    protected function parse(string $line): ?array
    {
        $fields = json_decode($line, associative: true);

        if (! is_array($fields) || ! is_string($fields['entry'] ?? null) || ! is_numeric($fields['at'] ?? null) || ! is_string($fields['kind'] ?? null)) {
            return null;
        }

        return [
            'at' => (float) $fields['at'],
            'entry' => $fields['entry'],
            'kind' => $fields['kind'],
            'message' => is_string($fields['message'] ?? null) ? $fields['message'] : '',
            'dropped' => is_int($fields['dropped'] ?? null) ? $fields['dropped'] : 0,
        ];
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
     * Append a failure's line to the failure file under an exclusive lock, keeping only its newest lines.
     */
    protected function append(FailureEntry $entry, Throwable $exception, FailureKind $kind, ?int $dropped = null): void
    {
        $created = ! file_exists($this->path);
        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Firewatch could not open [{$this->path}].");
        }

        try {
            if ($created) {
                chmod($this->path, static::FILE_MODE);
            }

            FileLock::acquire($handle, $this->path, static::BUSY_TIMEOUT_MILLISECONDS);

            $held = (string) stream_get_contents($handle);
            $lines = array_values(array_filter(explode("\n", $held), fn (string $line) => $line !== ''));

            // The line takes its instant under the lock, so the lines stay in the order of their instants.
            $line = $this->line($entry, $exception, $kind, $dropped);

            // Below the cap the line is appended, so a failed write never loses the earlier lines.
            if (count($lines) < self::LINES) {
                // A last line that a failed write cut short ends here, so it can't run into this one.
                $ending = $held === '' || str_ends_with($held, "\n") ? '' : "\n";

                fwrite($handle, $ending.$line."\n");
            } else {
                $newest = array_slice($lines, 1 - self::LINES);

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode("\n", [...$newest, $line])."\n");
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write a notice about the first failure.
     */
    protected function notify(Throwable $exception, string $failed): void
    {
        if ($this->notified) {
            return;
        }

        $this->notified = true;

        $this->notices->write("Firewatch {$failed}: {$exception->getMessage()}");
    }
}
