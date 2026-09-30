<?php

namespace ClaudioDekker\Firewatch\Capture;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Schema;
use JsonException;

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
     * The wire fields of a record of an unknown type that fill a common column, by column.
     */
    protected const UNKNOWN_TYPE_FIELDS = [
        't' => 'type',
        'v' => 'v',
        'timestamp' => 'started_at',
        'duration' => 'duration',
        '_group' => 'group_hash',
        'trace_id' => 'trace_id',
        'execution_id' => 'execution_id',
        'execution_source' => 'source',
        'job_id' => 'job_id',
        'user' => 'user_id',
        'deploy' => 'deploy',
        'server' => 'server',
    ];

    /**
     * Map a wire record to the columns of a stored record.
     *
     * @param  array<mixed>  $record
     * @return array<string, mixed>
     */
    public function map(array $record): array
    {
        $wire = $this->normalise($record);
        $type = is_string($wire['t'] ?? null) ? RecordType::tryFrom($wire['t']) : null;

        [$columns, $data] = $this->split($wire, $type);

        // The round trip can turn a float into an integer, so the instant comes from the original array.
        $columns['started_at'] = $this->startedAt($type, timestamp: $record['timestamp'] ?? null, duration: $columns['duration']);

        if ($type !== null) {
            $columns['execution_id'] = $this->executionId($type, $columns);
            $columns['source'] = $type->source() ?? $columns['source'];
        }

        $columns['data'] = json_encode((object) $data, self::JSON_FLAGS);

        return $columns;
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

        return (array) json_decode($json, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Split the wire fields into the common columns and the fields kept in data.
     *
     * @param  array<mixed>  $wire
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    protected function split(array $wire, ?RecordType $type): array
    {
        $fields = $type?->fields() ?? static::UNKNOWN_TYPE_FIELDS;
        $jsonFields = $type?->jsonFields() ?? [];
        $columns = array_fill_keys(Schema::COLUMNS, null);
        $data = [];

        foreach ($wire as $field => $value) {
            if (! array_key_exists($field, $fields)) {
                $data[$field] = $value;

                continue;
            }

            $name = $fields[$field];

            if ($name === null) {
                continue;
            }

            if (in_array($field, $jsonFields, strict: true)) {
                $value = $this->decode($type, $field, $value);
            }

            if (array_key_exists($name, $columns)) {
                $columns[$name] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        return [$columns, $data];
    }

    /**
     * Decode a JSON-string field, keeping the string as sent when it is not JSON.
     */
    protected function decode(?RecordType $type, string $field, mixed $value): mixed
    {
        // A fatal error sends its exception without a trace, as an empty string.
        if ($type === RecordType::EXCEPTION && $field === 'trace' && $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    /**
     * Get the instant a record started at, moving the types Nightwatch stamps at their end back by their duration.
     */
    protected function startedAt(?RecordType $type, mixed $timestamp, mixed $duration): mixed
    {
        if ($type?->isStampedAtEnd() && is_numeric($timestamp) && is_numeric($duration)) {
            return $timestamp - $duration / 1e6;
        }

        return $timestamp;
    }

    /**
     * Get the execution a record of a known type belongs to, or that an execution is.
     *
     * @param  array<string, mixed>  $columns
     */
    protected function executionId(RecordType $type, array $columns): mixed
    {
        return match (true) {
            $type === RecordType::JOB_ATTEMPT => $columns['execution_id'],
            $type->source() !== null => $columns['trace_id'],
            $type === RecordType::EXCEPTION && $columns['execution_id'] === '' => $this->fatalErrorExecutionId($columns),
            default => $columns['execution_id'],
        };
    }

    /**
     * Get the execution of a fatal error, which Nightwatch sends without one: its trace, unless it ended a job.
     *
     * @param  array<string, mixed>  $columns
     */
    protected function fatalErrorExecutionId(array $columns): mixed
    {
        return $columns['source'] === RecordType::JOB_ATTEMPT->source() ? null : $columns['trace_id'];
    }
}
