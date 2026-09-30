<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
class Truncator
{
    /**
     * The most bytes a string field keeps, truncation marker included.
     */
    public const FIELD_LIMIT = 65_535;

    /**
     * The most bytes a record's serialized data keeps before its largest strings are cut.
     */
    public const DATA_LIMIT = 1_048_576;

    /**
     * The bytes a string is cut to when its record's data is over the limit.
     */
    public const OVERSIZE_FIELD_LIMIT = 4_096;

    /**
     * Cut a string longer than the limit on a UTF-8 character boundary, ending it with the truncation marker.
     */
    public function cut(string $value, int $limit = self::FIELD_LIMIT): string
    {
        $bytes = strlen($value);

        if ($bytes <= $limit) {
            return $value;
        }

        $marker = "... [truncated, {$bytes} bytes total]";

        return mb_strcut($value, 0, $limit - strlen($marker), 'UTF-8').$marker;
    }

    /**
     * Serialize a record's data, cutting its strings to the field limit and, over the data limit, its largest strings further and then the trace's code snippets.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $exempt  the fields Nightwatch sent as JSON strings, which are kept whole
     */
    public function serialize(array $data, array $exempt): string
    {
        $cuttable = $this->cuttable($data, $exempt);
        $fitted = [...$data, ...array_map(fn (string $value) => $this->cut($value), $cuttable)];

        $json = $this->encode($fitted);

        foreach ($this->largestFirst($cuttable) as $field) {
            if ($this->fits($json)) {
                return $json;
            }

            $fitted[$field] = $this->cut($data[$field], self::OVERSIZE_FIELD_LIMIT);
            $json = $this->encode($fitted);
        }

        if ($this->fits($json) || ! array_key_exists('trace', $fitted)) {
            return $json;
        }

        $fitted['trace'] = $this->withoutCode($fitted['trace']);

        return $this->encode($fitted);
    }

    /**
     * Get the string fields of data that may be cut.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $exempt
     * @return array<string, string>
     */
    protected function cuttable(array $data, array $exempt): array
    {
        return array_filter(
            array_diff_key($data, array_flip($exempt)),
            fn (mixed $value) => is_string($value),
        );
    }

    /**
     * Get the fields an oversized record's data cuts further, largest first.
     *
     * @param  array<string, string>  $cuttable
     * @return list<string>
     */
    protected function largestFirst(array $cuttable): array
    {
        $lengths = array_filter(
            array_map(strlen(...), $cuttable),
            fn (int $bytes) => $bytes > self::OVERSIZE_FIELD_LIMIT,
        );

        arsort($lengths);

        return array_keys($lengths);
    }

    /**
     * Determine if serialized data is within the data limit.
     */
    protected function fits(string $json): bool
    {
        return strlen($json) <= self::DATA_LIMIT;
    }

    /**
     * Null the code snippet of every frame of a decoded trace.
     */
    protected function withoutCode(mixed $trace): mixed
    {
        if (! is_array($trace)) {
            return $trace;
        }

        foreach ($trace as $frame) {
            if (is_object($frame) && property_exists($frame, 'code')) {
                $frame->code = null;
            }
        }

        return $trace;
    }

    /**
     * Encode a record's data as its stored JSON object.
     *
     * @param  array<string, mixed>  $data
     */
    protected function encode(array $data): string
    {
        return json_encode((object) $data, RecordMapper::JSON_FLAGS);
    }
}
