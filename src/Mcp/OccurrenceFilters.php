<?php

namespace ClaudioDekker\Firewatch\Mcp;

use BackedEnum;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;

/**
 * @internal
 */
class OccurrenceFilters
{
    /**
     * The example every refusal of the occurrences tool gives.
     */
    public const EXAMPLE = 'occurrences(type: "request")';

    /**
     * Create a new occurrence filters instance.
     *
     * @param  array{int, int}|null  $status  the lowest and the highest status code that is kept
     */
    public function __construct(
        public readonly ?string $method = null,
        public readonly ?array $status = null,
        public readonly ?string $statusText = null,
        public readonly ?Outcome $outcome = null,
        public readonly ?LogLevel $level = null,
        public readonly int|float|null $slowerThanMilliseconds = null,
        public readonly ?Percentile $atOrAbove = null,
        public readonly ?string $matching = null,
    ) {
        //
    }

    /**
     * Read the filters of a call.
     */
    public static function from(Arguments $arguments): self
    {
        $status = $arguments->text('status', self::EXAMPLE);

        return new self(
            method: $arguments->text('method', self::EXAMPLE),
            status: $status === null ? null : self::statusRange($arguments, $status),
            statusText: $status,
            outcome: self::choice($arguments, 'outcome', Outcome::class, 'an outcome', 'occurrences(type: "job-attempt", outcome: "failed")'),
            level: self::choice($arguments, 'level', LogLevel::class, 'a log level', 'occurrences(type: "log", level: "error")'),
            slowerThanMilliseconds: self::slowerThan($arguments),
            atOrAbove: self::choice($arguments, 'at_or_above', Percentile::class, 'median or p95', 'occurrences(type: "request", at_or_above: "p95")'),
            matching: $arguments->matching('occurrences(type: "log", matching: "timeout")'),
        );
    }

    /**
     * Get the filters the call was given, by argument, as the words of an empty answer name them.
     *
     * @return array<string, string|int|float|null>
     */
    public function given(): array
    {
        return [
            'method' => $this->method,
            'status' => $this->statusText,
            'outcome' => $this->outcome?->value,
            'level' => $this->level?->value,
            'slower_than_ms' => $this->slowerThanMilliseconds,
            'at_or_above' => $this->atOrAbove?->value,
            'matching' => $this->matching,
        ];
    }

    /**
     * Refuse a filter, or an order, that does not fit the type, and an outcome the type does not have.
     *
     * A null type is one the call has not named and the store has not resolved: every filter that needs a type is refused.
     */
    public function refuseMisfits(Order $order, ?RecordType $type, string $selector): void
    {
        $timed = array_values(array_filter(RecordType::events(), fn (RecordType $type) => ! in_array($type, [RecordType::EXCEPTION, RecordType::LOG], true)));
        $executions = ExecutionType::records();
        $requests = [RecordType::REQUEST, RecordType::OUTGOING_REQUEST];

        $rules = [
            ['method', $this->method !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['status', $this->status !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['outcome', $this->outcome !== null, [RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK], true, 'a call with `type` job-attempt or scheduled-task'],
            ['level', $this->level !== null, [RecordType::LOG], true, 'a call with `type` log'],
            ['slower_than_ms', $this->slowerThanMilliseconds !== null, $timed, false, 'a call with a timed `type`'],
            ['order', $order === Order::SLOWEST, $timed, false, 'a call with a timed `type`'],
            ['order', in_array($order, [Order::MEMORY, Order::QUERIES], true), $executions, true, 'a call with `type` request, command, job-attempt or scheduled-task'],
            ['at_or_above', $this->atOrAbove !== null, $timed, true, 'a call with a timed `type`'],
            ['matching', $this->matching !== null, RecordType::events(), true, 'a call with a `type`'],
        ];

        foreach ($rules as [$argument, $given, $fits, $needsType, $accepted]) {
            $misfit = $type === null ? $needsType : ! in_array($type, $fits, true);

            if ($given && $misfit) {
                throw Refusal::conflicting(argument: $argument, with: $type === null ? $selector : $type->value, accepted: $accepted, example: self::EXAMPLE);
            }
        }

        if ($this->outcome !== null && $type !== null) {
            $this->refuseOutcome($this->outcome, $type);
        }
    }

    /**
     * Get the condition and bindings of the filters that act on a record, for the records of the type.
     *
     * @return array{string, array<string, string|int|float>}
     */
    public function condition(?RecordType $type): array
    {
        $conditions = [];
        $bindings = [];

        if ($this->method !== null) {
            $conditions[] = "upper(json_extract(data, '\$.method')) = upper(:method)";
            $bindings['method'] = $this->method;
        }

        if ($this->status !== null) {
            $conditions[] = "json_extract(data, '\$.status_code') BETWEEN :status_from AND :status_to";
            [$bindings['status_from'], $bindings['status_to']] = $this->status;
        }

        if ($this->outcome !== null) {
            $conditions[] = "json_extract(data, '\$.status') = :outcome";
            $bindings['outcome'] = $this->outcome->value;
        }

        if ($this->level !== null) {
            $names = [];

            foreach ($this->level->andWorse() as $at => $level) {
                $names[] = ":level{$at}";
                $bindings["level{$at}"] = $level;
            }

            $conditions[] = "lower(json_extract(data, '\$.level')) IN (".implode(', ', $names).')';
        }

        if ($this->slowerThanMilliseconds !== null) {
            $conditions[] = Stored::number('duration').' > :slower';
            $bindings['slower'] = $this->slowerThanMilliseconds * Microseconds::PER_MILLISECOND;
        }

        if ($this->matching !== null && $type !== null) {
            $fields = array_map(fn (string $field) => "instr(lower(COALESCE(json_extract(data, '\$.{$field}'), '')), lower(:matching)) > 0", self::matchedFields($type));
            $conditions[] = '('.implode(' OR ', $fields).')';
            $bindings['matching'] = $this->matching;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Get the first field of the type, in the order they are listed, that holds the matching.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchedOn(RecordType $type, array $data): ?string
    {
        if ($this->matching === null) {
            return null;
        }

        foreach (self::matchedFields($type) as $field) {
            if (is_string($data[$field] ?? null) && str_contains(strtolower($data[$field]), strtolower($this->matching))) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get the fields of a type a `matching` is looked for in, in the order the first one that matches is named.
     *
     * @return list<string>
     */
    protected static function matchedFields(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => ['route_path', 'route_name', 'route_action', 'url'],
            RecordType::COMMAND => ['name', 'command'],
            RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK, RecordType::QUEUED_JOB => ['name'],
            RecordType::QUERY => ['sql'],
            RecordType::EXCEPTION => ['class', 'message', 'file'],
            RecordType::LOG => ['message'],
            RecordType::CACHE_EVENT => ['key'],
            RecordType::OUTGOING_REQUEST => ['host', 'url'],
            RecordType::MAIL => ['class', 'subject'],
            RecordType::NOTIFICATION => ['class'],
            RecordType::USER => [],
        };
    }

    /**
     * Refuse an outcome the type does not have.
     */
    protected function refuseOutcome(Outcome $outcome, RecordType $type): void
    {
        $outcomes = Outcome::for($type);

        if (! in_array($outcome, $outcomes, true)) {
            $shown = json_encode($outcome->value, JSON_THROW_ON_ERROR);
            $accepted = implode(', ', array_map(fn (Outcome $outcome) => $outcome->value, $outcomes));

            throw Refusal::invalid(argument: 'outcome', expected: 'an outcome of the type', value: $shown, accepted: $accepted, example: 'occurrences(type: "job-attempt", outcome: "failed")');
        }
    }

    /**
     * Read a status as the lowest and the highest code it keeps.
     *
     * @return array{int, int}
     */
    protected static function statusRange(Arguments $arguments, string $status): array
    {
        $classes = [
            '1xx' => 100,
            '2xx' => 200,
            '3xx' => 300,
            '4xx' => 400,
            '5xx' => 500,
        ];
        $class = $classes[$status] ?? null;

        if ($class !== null) {
            return [$class, $class + 99];
        }

        if (preg_match('/^\d{3}(?:-\d{3})?$/', $status) === 1) {
            [$from, $to] = sscanf($status, '%d-%d') + [1 => null];

            if ($from <= ($to ?? $from)) {
                return [$from, $to ?? $from];
            }
        }

        throw $arguments->invalid(argument: 'status', expected: 'a status such as 500, 5xx or 400-499', accepted: '500, 5xx or 400-499', example: 'occurrences(type: "request", status: "5xx")');
    }

    /**
     * Read one of the cases of a filter that is text.
     *
     * @template TCase of BackedEnum
     *
     * @param  class-string<TCase>  $enum
     * @return TCase|null
     */
    protected static function choice(Arguments $arguments, string $argument, string $enum, string $expected, string $example): ?BackedEnum
    {
        if ($arguments->text($argument, self::EXAMPLE) === null) {
            return null;
        }

        return $arguments->choice($argument, $enum, $expected, $example);
    }

    /**
     * Read the milliseconds a record must be slower than.
     */
    protected static function slowerThan(Arguments $arguments): int|float|null
    {
        $value = $arguments->get('slower_than_ms');

        if ($value === null) {
            return null;
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return $value;
        }

        throw $arguments->invalid(argument: 'slower_than_ms', expected: 'a number of milliseconds from 0', accepted: 'a number of milliseconds from 0', example: 'occurrences(type: "request", slower_than_ms: 500)');
    }
}
