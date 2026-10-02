<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class ExceptionSection
{
    /**
     * The most exceptions an answer shows.
     */
    public const MAXIMUM = 5;

    /**
     * The most frames with source lines Nightwatch stores for one exception.
     */
    protected const CODE_FRAME_LIMIT = 10;

    /**
     * Get the earliest exceptions among the children, as many as are shown, and how many there are in all.
     *
     * @param  list<array<string, mixed>>  $children
     * @return array{rows: list<array<string, mixed>>, matched: int}
     */
    public static function of(array $children): array
    {
        $exceptions = array_values(array_filter($children, fn (array $child) => $child['type'] === RecordType::EXCEPTION->value));
        $shown = array_slice($exceptions, 0, self::MAXIMUM);

        return [
            'rows' => array_map(self::row(...), $shown),
            'matched' => count($exceptions),
        ];
    }

    /**
     * Get one exception with the frames of its stored trace.
     *
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    protected static function row(array $exception): array
    {
        [$frames, $note] = self::frames(Stored::json($exception['trace'] ?? null));

        return [
            'class' => $exception['class'] ?? null,
            'message' => $exception['message'] ?? null,
            'handled' => Stored::flag($exception['handled'] ?? null),
            'location' => Stored::location($exception['file'] ?? null, $exception['line'] ?? null),
            'frames' => $frames,
            'frames_note' => $note,
        ];
    }

    /**
     * Get the frames of a trace: each application frame with the lines Nightwatch stored for it, and each run of vendor frames as one entry.
     *
     * The note says why an application frame has no lines when one has none.
     *
     * @return array{list<array<string, mixed>>, string|null}
     */
    protected static function frames(mixed $trace): array
    {
        if (! is_array($trace)) {
            return [[], null];
        }

        $frames = [];
        $vendor = 0;
        $withCode = 0;
        $missing = false;

        foreach ($trace as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $file = is_string($frame['file'] ?? null) ? $frame['file'] : '';
            $code = is_array($frame['code'] ?? null) ? $frame['code'] : null;
            $withCode += $code === null ? 0 : 1;

            if (! self::isApplication($file)) {
                $vendor++;

                continue;
            }

            if ($vendor > 0) {
                $frames[] = ['vendor_frames' => $vendor];
                $vendor = 0;
            }

            $missing = $missing || $code === null;
            $frames[] = [
                'file' => $file,
                'source' => Stored::blank($frame['source'] ?? null),
                'code' => $code,
            ];
        }

        if ($vendor > 0) {
            $frames[] = ['vendor_frames' => $vendor];
        }

        return [$frames, $missing ? self::note($withCode) : null];
    }

    /**
     * Determine if a frame's file is the application's: not under vendor, and not one of the placeholders for a frame with no file.
     */
    protected static function isApplication(string $file): bool
    {
        if ($file === '' || str_starts_with($file, '[')) {
            return false;
        }

        return ! str_starts_with($file, 'vendor/') && ! str_contains($file, '/vendor/');
    }

    /**
     * Get the sentence that says whether Nightwatch's limit on frames with source lines was reached.
     */
    protected static function note(int $withCode): string
    {
        return $withCode >= self::CODE_FRAME_LIMIT
            ? __('firewatch::messages.execution_frames_limit_reached')
            : __('firewatch::messages.execution_frames_limit_not_reached');
    }
}
