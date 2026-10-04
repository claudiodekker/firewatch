<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Instant;
use RuntimeException;
use SQLite3Exception;
use Throwable;

/**
 * @internal
 */
class FailureLog
{
    /**
     * The name of the file beside the store that records each dropped batch.
     */
    public const FILE = 'failures.jsonl';

    /**
     * The most lines the file keeps; older ones are rewritten away.
     */
    public const LINES = 100;

    /**
     * The mode of the failure file.
     */
    protected const FILE_MODE = 0600;

    /**
     * Whether this process has reported a dropped batch.
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
     * Record a dropped batch beside the store and report the first of the process, swallowing any failure of either.
     */
    public function record(Throwable $exception, int $dropped): void
    {
        try {
            $this->append($this->line($exception, FailureKind::of($exception), $dropped));
        } catch (Throwable) {
            //
        }

        try {
            $this->report($exception, $dropped);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Record a damaged store the writer replaced, with nothing dropped, swallowing any failure; its damage may read as a file that is not a database.
     */
    public function recovered(Throwable $exception): void
    {
        try {
            $this->append($this->line($exception, FailureKind::CORRUPT, dropped: 0));
        } catch (Throwable) {
            //
        }
    }

    /**
     * Read the dropped batches the file still holds, oldest first; a missing or unreadable file, a recovery that dropped nothing and a line that isn't a record hold none.
     *
     * @return list<array{at: float, kind: string, dropped: int}>
     */
    public function dropped(): array
    {
        $lines = @file(dirname($this->configuration->database).'/'.static::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $dropped = [];

        foreach ($lines ?: [] as $line) {
            $entry = json_decode($line, associative: true);

            if (is_array($entry) && is_numeric($entry['at'] ?? null) && is_string($entry['kind'] ?? null) && is_int($entry['dropped'] ?? null) && $entry['dropped'] > 0) {
                $dropped[] = [
                    'at' => (float) $entry['at'],
                    'kind' => $entry['kind'],
                    'dropped' => $entry['dropped'],
                ];
            }
        }

        return $dropped;
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
     * Get the line that records a dropped batch.
     */
    protected function line(Throwable $exception, FailureKind $kind, int $dropped): string
    {
        return json_encode([
            'at' => Instant::now(),
            'kind' => $kind->value,
            'code' => $exception instanceof SQLite3Exception ? $exception->getCode() : null,
            'message' => $exception->getMessage(),
            'dropped' => $dropped,
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
     * Report the process's first dropped batch through the exception handler.
     */
    protected function report(Throwable $exception, int $dropped): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;

        report(new RuntimeException("Firewatch could not store a batch of {$dropped} records: {$exception->getMessage()}", previous: $exception));
    }
}
