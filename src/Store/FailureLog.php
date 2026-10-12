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
            $this->append($this->line(FailureEntry::RECOVERY, $exception, FailureKind::CORRUPT));
        } catch (Throwable) {
            //
        }
    }

    /**
     * Read the dropped batches the file holds, oldest first, each line counting one batch or the batches folded into it.
     *
     * @return list<array{at: float, until: float, kind: string, dropped: int, batches: int, message: string}>
     */
    public function dropped(): array
    {
        $dropped = [];

        foreach ($this->entries(FailureEntry::BATCH) as $entry) {
            if ($entry['dropped'] > 0) {
                $dropped[] = [
                    'at' => $entry['at'],
                    'until' => $entry['until'],
                    'kind' => $entry['kind'],
                    'dropped' => $entry['dropped'],
                    'batches' => $entry['batches'],
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
            $this->append($this->line($entry, $exception, FailureKind::of($exception), $dropped));
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
     * @return list<array{at: float, until: float, entry: string, kind: string, code: int|null, message: string, dropped: int, batches: int}>
     */
    protected function entries(FailureEntry $entry): array
    {
        $lines = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $parsed = array_map($this->parse(...), $lines ?: []);

        return array_values(array_filter($parsed, fn (?array $fields) => $fields !== null && $fields['entry'] === $entry->value));
    }

    /**
     * Read a line of the file, or null when it is not well formed.
     *
     * @return array{at: float, until: float, entry: string, kind: string, code: int|null, message: string, dropped: int, batches: int}|null
     */
    protected function parse(string $line): ?array
    {
        $fields = json_decode($line, associative: true);

        if (! is_array($fields) || ! is_string($fields['entry'] ?? null) || ! is_numeric($fields['at'] ?? null) || ! is_string($fields['kind'] ?? null)) {
            return null;
        }

        return [
            'at' => (float) $fields['at'],
            'until' => (float) (is_numeric($fields['until'] ?? null) ? $fields['until'] : $fields['at']),
            'entry' => $fields['entry'],
            'kind' => $fields['kind'],
            'code' => is_int($fields['code'] ?? null) ? $fields['code'] : null,
            'message' => is_string($fields['message'] ?? null) ? $fields['message'] : '',
            'dropped' => is_int($fields['dropped'] ?? null) ? $fields['dropped'] : 0,
            'batches' => is_int($fields['batches'] ?? null) ? $fields['batches'] : 1,
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
     * Append a line to the failure file under an exclusive lock, keeping the file to its most lines.
     */
    protected function append(string $line): void
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

            $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($handle)), fn (string $held) => $held !== ''));

            // Below the cap the line is appended, so a failed write never loses the earlier lines.
            if (count($lines) < self::LINES) {
                fwrite($handle, $line."\n");
            } else {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode("\n", [...$this->makeRoom($lines), $line])."\n");
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Free one line without losing a count: drop a line that can't be read, else fold two dropped batches of one kind, else drop the oldest other line.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function makeRoom(array $lines): array
    {
        $parsed = array_map($this->parse(...), $lines);
        $readable = array_filter($parsed);

        foreach (array_keys($lines) as $index) {
            if (! isset($readable[$index])) {
                return $this->without($lines, $index);
            }
        }

        $firstOfKind = [];

        foreach ($readable as $index => $fields) {
            if ($fields['entry'] !== FailureEntry::BATCH->value) {
                continue;
            }

            if (isset($firstOfKind[$fields['kind']])) {
                $lines[$index] = $this->fold($readable[$firstOfKind[$fields['kind']]], $fields);

                return $this->without($lines, $firstOfKind[$fields['kind']]);
            }

            $firstOfKind[$fields['kind']] = $index;
        }

        foreach ($readable as $index => $fields) {
            if ($fields['entry'] !== FailureEntry::BATCH->value) {
                return $this->without($lines, $index);
            }
        }

        return $this->without($lines, 0);
    }

    /**
     * Get the line that counts the dropped batches of two lines of one kind, from the first of the older to the last of the newer.
     *
     * @param  array{at: float, until: float, entry: string, kind: string, code: int|null, message: string, dropped: int, batches: int}  $older
     * @param  array{at: float, until: float, entry: string, kind: string, code: int|null, message: string, dropped: int, batches: int}  $newer
     */
    protected function fold(array $older, array $newer): string
    {
        return json_encode([
            ...$newer,
            'at' => $older['at'],
            'dropped' => $older['dropped'] + $newer['dropped'],
            'batches' => $older['batches'] + $newer['batches'],
        ], RecordMapper::JSON_FLAGS);
    }

    /**
     * Get the lines without the one at the given position.
     *
     * @param  array<int, string>  $lines
     * @return list<string>
     */
    protected function without(array $lines, int $index): array
    {
        unset($lines[$index]);

        return array_values($lines);
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
