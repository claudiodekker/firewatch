<?php

namespace ClaudioDekker\Firewatch\Capture;

use ClaudioDekker\Firewatch\Notices;
use ClaudioDekker\Firewatch\RecordType;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @internal
 */
class InvalidFields
{
    /**
     * The most entries a request keeps: field names, the last of which counts the names left out when there were more.
     */
    public const FIELD_LIMIT = 100;

    /**
     * The most bytes one field name keeps, truncation marker included.
     */
    public const NAME_LIMIT_BYTES = 255;

    /**
     * The entry that stands for the field names left out, by their count.
     */
    protected const MARKER = '... [%d more fields]';

    /**
     * The status the kernel answered last and the fields that failed validation in that request, until Nightwatch records it.
     *
     * @var array{status: int, fields: list<string>}|null
     */
    protected ?array $pending = null;

    /**
     * Create a new invalid fields instance.
     */
    public function __construct(protected Truncator $truncator, protected Notices $notices)
    {
        //
    }

    /**
     * Hold the fields that failed validation when the response was rendered from a validation exception, and forget those of the request before.
     */
    public function capture(RequestHandled $event): void
    {
        $this->pending = null;

        // Laravel never reports a validation exception, so the response it was rendered into is the only place it shows.
        $rendered = $event->response->exception ?? null;

        if (! $rendered instanceof ValidationException) {
            return;
        }

        // A validator that fails to list its errors leaves the request unpaired rather than failing the application's response.
        try {
            $this->pending = [
                'status' => $event->response->getStatusCode(),
                'fields' => $this->fit(array_map(strval(...), array_keys($rendered->errors()))),
            ];
        } catch (Throwable $exception) {
            $this->notices->write("Firewatch could not read the fields a request failed validation on: {$exception->getMessage()}");
        }
    }

    /**
     * Get the fields that failed validation in the request a wire record was written for.
     *
     * @param  array<mixed>  $record
     * @return list<string>|null
     */
    public function pair(array $record): ?array
    {
        if (($record['t'] ?? null) !== RecordType::REQUEST->value) {
            return null;
        }

        $pending = $this->pending;

        $this->pending = null;

        // A request handled while another terminates is the one the kernel handled last, but not the one being recorded.
        if ($pending === null || ($record['status_code'] ?? null) !== $pending['status']) {
            return null;
        }

        return $pending['fields'];
    }

    /**
     * Keep the names that fit the limit, each cut to its own, replacing the rest with their count.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    protected function fit(array $names): array
    {
        $names = array_map(fn (string $name) => $this->truncator->cut($name, static::NAME_LIMIT_BYTES), $names);

        if (count($names) <= static::FIELD_LIMIT) {
            return $names;
        }

        $kept = static::FIELD_LIMIT - 1;
        $more = count($names) - $kept;

        return [...array_slice($names, 0, $kept), sprintf(static::MARKER, $more)];
    }
}
