<?php

namespace ClaudioDekker\Firewatch\Capture;

use ClaudioDekker\Firewatch\ExecutionType;

/**
 * @internal
 */
class RecordMapper
{
    /**
     * The flags Nightwatch encodes its records with, so wire floats keep their fraction.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    /**
     * The wire fields that fill a common column rather than data.
     */
    protected const COLUMN_FIELDS = [
        't',
        'v',
        'timestamp',
        'duration',
        '_group',
        'trace_id',
        'execution_id',
        'execution_source',
        'job_id',
        'user',
        'deploy',
        'server',
    ];

    /**
     * Map a wire record to the columns of a stored record.
     *
     * @param  array<mixed>  $record
     * @return array{type: mixed, v: mixed, started_at: mixed, duration: mixed, group_hash: mixed, trace_id: mixed, execution_id: mixed, source: mixed, job_id: mixed, user_id: mixed, deploy: mixed, server: mixed, data: string}
     */
    public function map(array $record): array
    {
        $wire = $this->normalise($record);
        $isRequest = ($wire['t'] ?? null) === ExecutionType::REQUEST->value;
        $data = array_diff_key($wire, array_flip(static::COLUMN_FIELDS));

        return [
            'type' => $wire['t'] ?? null,
            'v' => $wire['v'] ?? null,
            // The round trip can turn a float into an integer, so the instant comes from the original array.
            'started_at' => $record['timestamp'] ?? null,
            'duration' => $wire['duration'] ?? null,
            'group_hash' => $wire['_group'] ?? null,
            'trace_id' => $wire['trace_id'] ?? null,
            'execution_id' => $isRequest ? ($wire['trace_id'] ?? null) : ($wire['execution_id'] ?? null),
            'source' => $isRequest ? ExecutionType::REQUEST->value : ($wire['execution_source'] ?? null),
            'job_id' => $wire['job_id'] ?? null,
            'user_id' => $wire['user'] ?? null,
            'deploy' => $wire['deploy'] ?? null,
            'server' => $wire['server'] ?? null,
            'data' => json_encode((object) $data, self::JSON_FLAGS),
        ];
    }

    /**
     * Turn a record into the plain data Nightwatch would send, resolving its lazy values.
     *
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    protected function normalise(array $record): array
    {
        $json = json_encode($record, self::JSON_FLAGS);

        return (array) json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
