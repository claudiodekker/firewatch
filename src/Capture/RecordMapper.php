<?php

namespace ClaudioDekker\Firewatch\Capture;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;
use ClaudioDekker\Firewatch\Store\Schema;
use JsonException;
use stdClass;

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
     * Create a new record mapper instance.
     */
    public function __construct(protected Truncator $truncator)
    {
        //
    }

    /**
     * Map a wire record to a stored record or a user directory entry.
     *
     * @param  array<mixed>  $record
     * @param  list<mixed>|null  $bindings
     */
    public function map(array $record, Drift $drift, ?array $bindings = null): MappedRecord
    {
        try {
            $wire = $this->normalise($record);
        } catch (JsonException $exception) {
            return $this->placeholder($record, $drift, detail: 'record: unencodable', error: $exception->getMessage());
        }

        if (! $wire instanceof stdClass) {
            return $this->placeholder($record, $drift, detail: 'record: not an object');
        }

        $wire = (array) $wire;

        if (! array_key_exists('t', $wire)) {
            return $this->placeholder($wire, $drift, detail: 't: missing');
        }

        if (! is_string($wire['t'])) {
            return $this->placeholder($wire, $drift, detail: 't: expected string, got '.$this->jsonType($wire['t']));
        }

        $type = RecordType::tryFrom($wire['t']);

        $this->check($wire, $type, $drift);

        $seenAt = $this->instant($record['timestamp'] ?? null);
        $user = $this->user($type, $wire, seenAt: $seenAt);

        if ($user !== null) {
            return new MappedRecord(user: $user);
        }

        $columns = $this->columns($type, $wire, timestamp: $record['timestamp'] ?? null, bindings: $bindings);

        return new MappedRecord(record: $columns);
    }

    /**
     * Map the wire fields of a record to the columns of a stored record.
     *
     * @param  array<mixed>  $wire
     * @param  list<mixed>|null  $bindings
     * @return array<string, mixed>
     */
    protected function columns(?RecordType $type, array $wire, mixed $timestamp, ?array $bindings): array
    {
        [$columns, $data, $jsonFields] = $this->split($wire, $type);

        if ($type === RecordType::QUERY) {
            $data['bindings'] = $bindings;
        }

        // A serialize_precision below 17 makes the round trip round a float, so the instant comes from the original array.
        $columns['started_at'] = $this->startedAt($type, timestamp: $timestamp, durationMicroseconds: $columns['duration']);

        if ($type !== null) {
            $columns['execution_id'] = $this->executionId($type, $columns);
            $columns['source'] = $type->source() ?? $columns['source'];
        }

        $trace = $type === RecordType::EXCEPTION ? 'trace' : null;

        $columns['data'] = $this->truncator->serialize($data, exempt: $jsonFields, trace: $trace);

        return $columns;
    }

    /**
     * Get the user directory entry of a user record.
     *
     * @param  array<mixed>  $wire
     * @return array{id: string, name: mixed, username: mixed, seen_at: int|float|null}|null
     */
    protected function user(?RecordType $type, array $wire, int|float|null $seenAt): ?array
    {
        if ($type !== RecordType::USER || ! $this->hasUsableId($wire)) {
            return null;
        }

        return [
            'id' => $wire['id'],
            'name' => $this->flatten($wire['name'] ?? null),
            'username' => $this->flatten($wire['username'] ?? null),
            'seen_at' => $seenAt,
        ];
    }

    /**
     * Determine if a user record carries an id the user directory can be keyed by.
     *
     * @param  array<mixed>  $wire
     */
    protected function hasUsableId(array $wire): bool
    {
        return is_string($wire['id'] ?? null) && $wire['id'] !== '';
    }

    /**
     * Count how a readable record differs from the contract table.
     *
     * @param  array<string, mixed>  $wire
     */
    protected function check(array $wire, ?RecordType $type, Drift $drift): void
    {
        $v = $wire['v'] ?? null;

        if ($type === null || ! $type->hasVersion($v)) {
            $drift->record($type === null ? DriftKind::UNKNOWN_TYPE : DriftKind::UNKNOWN_VERSION, type: $wire['t'], v: $v);

            // Without a contract its fields can't be judged, but the instant still fills a column.
            if (array_key_exists('timestamp', $wire)) {
                $this->checkField($wire, 'timestamp', accepts: $type?->acceptedTypes()['timestamp'] ?? RecordType::USER->acceptedTypes()['timestamp'], drift: $drift);
            }

            return;
        }

        $this->checkFields($wire, $type, $drift);

        if ($type === RecordType::USER && ($wire['id'] ?? null) === '') {
            $drift->record(DriftKind::MISSING_FIELD, type: $wire['t'], v: $v, detail: 'id');
        }
    }

    /**
     * Count the fields of a record of a known type and version that are missing, of a type it does not accept, or unknown.
     *
     * @param  array<string, mixed>  $wire
     */
    protected function checkFields(array $wire, RecordType $type, Drift $drift): void
    {
        $acceptedTypes = $type->acceptedTypes();

        foreach ($acceptedTypes as $field => $accepts) {
            if (array_key_exists($field, $wire)) {
                $this->checkField($wire, $field, accepts: $accepts, drift: $drift);
            } else {
                $drift->record(DriftKind::MISSING_FIELD, type: $wire['t'], v: $wire['v'], detail: $field);
            }
        }

        foreach (array_diff_key($wire, $acceptedTypes) as $field => $value) {
            $drift->record(DriftKind::UNKNOWN_FIELD, type: $wire['t'], v: $wire['v'], detail: (string) $field);
        }
    }

    /**
     * Count a field whose value is of a type the contract table does not accept.
     *
     * @param  array<string, mixed>  $wire
     * @param  list<string>  $accepts
     */
    protected function checkField(array $wire, string $field, array $accepts, Drift $drift): void
    {
        $jsonType = $this->jsonType($wire[$field]);

        // A JSON number may be written without a fraction.
        if (in_array($jsonType, $accepts, strict: true) || ($jsonType === 'integer' && in_array('number', $accepts, strict: true))) {
            return;
        }

        $drift->record(DriftKind::STRUCTURE, type: $wire['t'], v: $wire['v'] ?? null, detail: "{$field}: expected ".implode(' or ', $accepts).", got {$jsonType}");
    }

    /**
     * Store input that can't be read as a record as an error placeholder, and count it.
     *
     * @param  array<mixed>  $record
     */
    protected function placeholder(array $record, Drift $drift, string $detail, ?string $error = null): MappedRecord
    {
        $type = is_string($record['t'] ?? null) ? $record['t'] : null;

        $drift->record(DriftKind::STRUCTURE, type: $type, v: $record['v'] ?? null, detail: $detail);

        $columns = array_fill_keys(Schema::COLUMNS, null);

        $columns['type'] = $type;
        $columns['data'] = json_encode(['error' => $error ?? $detail], self::JSON_FLAGS);

        return new MappedRecord(record: $columns);
    }

    /**
     * Turn a record into the plain data Nightwatch would send, resolving its lazy values.
     *
     * @param  array<mixed>  $record
     *
     * @throws JsonException
     */
    protected function normalise(array $record): mixed
    {
        $json = json_encode($record, self::JSON_FLAGS);

        return json_decode($json, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Get the JSON type of a decoded wire value.
     */
    protected function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    /**
     * Split the wire fields into the common columns, the fields kept in data, and the names of those Nightwatch sent as JSON strings.
     *
     * @param  array<mixed>  $wire
     * @return array{array<string, mixed>, array<string, mixed>, list<string>}
     */
    protected function split(array $wire, ?RecordType $type): array
    {
        $fields = $type?->fields() ?? static::UNKNOWN_TYPE_FIELDS;
        $jsonFields = $type?->jsonFields() ?? [];
        $columns = array_fill_keys(Schema::COLUMNS, null);
        $data = [];
        $dataJsonFields = [];

        foreach ($wire as $field => $value) {
            if (! array_key_exists($field, $fields)) {
                $data[$field] = $value;

                continue;
            }

            $name = $fields[$field];

            if ($name === null) {
                continue;
            }

            $isJson = in_array($field, $jsonFields, strict: true);

            if ($isJson) {
                $value = $this->decode($type, $field, $value);
            }

            if (array_key_exists($name, $columns)) {
                $value = $this->flatten($value);

                $columns[$name] = is_string($value) ? $this->truncator->cut($value) : $value;
            } else {
                $data[$name] = $value;

                if ($isJson) {
                    $dataJsonFields[] = $name;
                }
            }
        }

        return [$columns, $data, $dataJsonFields];
    }

    /**
     * Get a list or an object as its JSON, so a column can hold it.
     */
    protected function flatten(mixed $value): mixed
    {
        return is_array($value) || is_object($value) ? json_encode($value, static::JSON_FLAGS) : $value;
    }

    /**
     * Decode a JSON-string field.
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
    protected function startedAt(?RecordType $type, mixed $timestamp, mixed $durationMicroseconds): int|float|null
    {
        $instant = $this->instant($timestamp);

        if ($instant !== null && $type?->isStampedAtEnd() && is_numeric($durationMicroseconds)) {
            return $instant - $durationMicroseconds / Microseconds::PER_SECOND;
        }

        return $instant;
    }

    /**
     * Get a wire timestamp as an instant.
     */
    protected function instant(mixed $timestamp): int|float|null
    {
        return is_int($timestamp) || is_float($timestamp) ? $timestamp : null;
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
     * Get the execution of a fatal error, which Nightwatch sends without one.
     *
     * @param  array<string, mixed>  $columns
     */
    protected function fatalErrorExecutionId(array $columns): mixed
    {
        return $columns['source'] === RecordType::JOB_ATTEMPT->source() ? null : $columns['trace_id'];
    }
}
