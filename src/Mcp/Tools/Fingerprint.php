<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Holdings;
use ClaudioDekker\Firewatch\Mcp\Recipe;
use ClaudioDekker\Firewatch\Mcp\RecipeCheck;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('fingerprint')]
#[Title('Fingerprint')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Fingerprint extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The source arguments each type takes.
     */
    protected const ARGUMENTS = [
        'request' => ['methods', 'path', 'domain'],
        'command' => ['name'],
        'job-attempt' => ['name'],
        'queued-job' => ['name'],
        'scheduled-task' => ['name', 'cron', 'timezone', 'repeat_seconds'],
        'query' => ['connection', 'sql', 'driver'],
        'cache-event' => ['store', 'key'],
        'outgoing-request' => ['host'],
        'mail' => ['class'],
        'notification' => ['class'],
    ];

    /**
     * The fields the recipes read under another name than the argument that gives them.
     */
    protected const FIELDS = [
        'methods' => 'route_methods',
        'path' => 'route_path',
        'domain' => 'route_domain',
    ];

    /**
     * The source arguments that may be left out.
     */
    protected const OPTIONAL = ['domain', 'timezone', 'repeat_seconds', 'driver'];

    /**
     * The seconds Laravel repeats a task within the minute by (the divisors of 60), and 0 for a task that does not repeat.
     */
    protected const REPEAT_SECONDS = [0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30];

    /**
     * The most stored records of a type the recipe check reads.
     */
    protected const CHECKED_RECORDS = 20;

    /**
     * The most candidate groups that offer `next` calls.
     */
    protected const NEXT_GROUPS = 2;

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.fingerprint');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $text = fn (string $argument) => $schema->string()->description(__("firewatch::messages.fingerprint_arguments.{$argument}"));

        return [
            'type' => $schema->string()->enum(array_keys(self::ARGUMENTS))->required(),
            'methods' => $schema->array()->items($schema->string())->description(__('firewatch::messages.fingerprint_arguments.methods')),
            'path' => $text('path'),
            'domain' => $text('domain'),
            'name' => $text('name'),
            'cron' => $text('cron'),
            'timezone' => $text('timezone'),
            'repeat_seconds' => $schema->integer()->enum(self::REPEAT_SECONDS)->default(0)->description(__('firewatch::messages.fingerprint_arguments.repeat_seconds')),
            'connection' => $text('connection'),
            'sql' => $text('sql'),
            'driver' => $text('driver'),
            'store' => $text('store'),
            'key' => $text('key'),
            'host' => $text('host'),
            'class' => $text('class'),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the group id of what the source facts name, the types that hold it, and the recipe check.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Compute the candidates from the arguments, then read the holders and the checks in one snapshot.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this);
        $type = $this->type($arguments);
        $fields = $this->fields($arguments, $type);
        $driver = $this->driver($arguments);
        $candidates = Recipe::candidates($type, $fields, $driver);
        $types = $this->types($type);

        $frame = $this->frame($now);

        $frame->about($types, Window::none(reason: __('firewatch::messages.fingerprint_window_reason'), timezone: $frame->timezone));

        $read = $frame->read(fn (SQLite3 $connection) => [
            Holdings::read($connection),
            $this->held($connection, $candidates, $types),
            array_map(fn (RecordType $checked) => $this->check($connection, $checked), $types),
            $this->ruledOut($connection, $type, $fields, $driver, $types),
        ]);

        if ($read === null) {
            $empty = $frame->unusable();

            return $frame->answer(
                summary: $empty->summary(),
                empty: $empty,
                result: $this->result($type, $candidates, [], $this->unchecked($types)),
                notes: $this->notes($request, $type),
            );
        }

        [$holdings, $held, $checks, $ruledOut] = $read;

        $population = array_sum(array_map($holdings->of(...), $types));
        $filter = 'group: '.implode(' or ', array_column($candidates, 'group'));
        $empty = match (true) {
            $frame->isEmpty() => $frame->storeEmpty(),
            $held === [] => Emptiness::noMatch($population, [$filter]),
            default => null,
        };

        $check = RecipeCheck::together(array_map(fn (array $check) => RecipeCheck::from($check['check']), $checks));

        return $frame->answer(
            summary: $this->summary($types, $candidates, $held, $check),
            empty: $empty,
            result: $this->result($type, $candidates, $held, $checks),
            notes: [...$this->notes($request, $type), ...$this->driverNotes($held, $ruledOut)],
            next: $this->next($held),
        );
    }

    /**
     * Get the result: the type, its candidates, the types that hold them and each type's recipe check.
     *
     * @param  list<array{group: string, input: string, assumption: string|null}>  $candidates
     * @param  list<array<string, mixed>>  $held
     * @param  list<array{type: string, check: string, detail: string|null}>  $checks
     * @return array<string, mixed>
     */
    protected function result(RecordType $type, array $candidates, array $held, array $checks): array
    {
        return [
            'type' => $type->value,
            'candidates' => $candidates,
            'held' => $held,
            'recipe_check' => $checks,
        ];
    }

    /**
     * Read the type to fingerprint: one of the ten types whose group source can give.
     */
    protected function type(Arguments $arguments): RecordType
    {
        $accepted = implode(', ', array_keys(self::ARGUMENTS));
        $example = $this->example(RecordType::REQUEST);
        $value = $arguments->get('type');
        $named = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($named !== null && ! array_key_exists($named->value, self::ARGUMENTS)) {
            throw Refusal::noRecipe($named->value, $accepted, $example);
        }

        return $arguments->requiredType('one of the ten types with a recipe', $example);
    }

    /**
     * Read the type's source arguments into the fields its recipe reads, refusing any that belong to another type.
     *
     * @return array<string, mixed>
     */
    protected function fields(Arguments $arguments, RecordType $type): array
    {
        $taken = $this->arguments($type);

        foreach ($arguments->names() as $argument) {
            if (! in_array($argument, ['type', 'format'], true) && ! in_array($argument, $taken, true)) {
                throw Refusal::conflicting(argument: $argument, with: "type: {$type->value}", accepted: $this->accepted($type), example: $this->example($type));
            }
        }

        foreach ($taken as $argument) {
            if ($arguments->get($argument) === null && ! in_array($argument, self::OPTIONAL, true)) {
                throw Refusal::missing($argument, $this->accepted($type), $this->example($type));
            }
        }

        $fields = $this->defaults($type);

        foreach (array_diff($taken, ['driver']) as $argument) {
            $value = $arguments->get($argument);

            if ($value !== null) {
                $fields[self::FIELDS[$argument] ?? $argument] = $this->value($arguments, $type, $argument, $value);
            }
        }

        return $fields;
    }

    /**
     * Read the driver of a query's connection, which picks one reading of its SQL.
     */
    protected function driver(Arguments $arguments): ?string
    {
        $value = $arguments->get('driver');

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw $arguments->invalid(argument: 'driver', expected: 'a non-empty string', accepted: 'a driver name, such as "mysql"', example: $this->example(RecordType::QUERY));
        }

        return strtolower($value);
    }

    /**
     * Get the fields an optional argument gives when it is left out.
     *
     * @return array<string, mixed>
     */
    protected function defaults(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => ['route_domain' => ''],
            RecordType::SCHEDULED_TASK => [
                'timezone' => $this->scheduleTimezone(),
                'repeat_seconds' => 0,
            ],
            default => [],
        };
    }

    /**
     * Get the timezone Laravel gives a scheduled task that names none: the schedule's when set, even to null, else the application's.
     */
    protected function scheduleTimezone(): string
    {
        $timezone = config('app.schedule_timezone', config('app.timezone'));

        return is_string($timezone) ? $timezone : '';
    }

    /**
     * Read one source argument as the field its recipe reads.
     */
    protected function value(Arguments $arguments, RecordType $type, string $argument, mixed $value): mixed
    {
        return match ($argument) {
            'methods' => $this->methods($arguments, $type, $value),
            'path' => '/'.trim($this->text($arguments, $type, $argument, $value), '/'),
            'domain' => str_replace(['http://', 'https://'], '', $this->text($arguments, $type, $argument, $value)),
            'repeat_seconds' => $this->repeatSeconds($arguments, $type, $value),
            default => $this->text($arguments, $type, $argument, $value),
        };
    }

    /**
     * Read the route methods as Nightwatch stores them: sorted, with HEAD wherever GET is, as Laravel registers every GET route.
     *
     * @return list<string>
     */
    protected function methods(Arguments $arguments, RecordType $type, mixed $value): array
    {
        if (! $this->isMethods($value)) {
            throw $arguments->invalid(argument: 'methods', expected: 'a non-empty list of uppercase HTTP methods', accepted: 'uppercase HTTP methods, such as ["GET","HEAD"]', example: $this->example($type));
        }

        $methods = array_values(array_unique(in_array('GET', $value, true) ? [...$value, 'HEAD'] : $value));

        sort($methods);

        return $methods;
    }

    /**
     * Determine if a value is a non-empty list of uppercase HTTP methods.
     *
     * @phpstan-assert-if-true list<string> $value
     */
    protected function isMethods(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        return array_filter($value, fn (mixed $method) => is_string($method) && preg_match('/^[A-Z]+$/', $method) === 1) === $value;
    }

    /**
     * Read the repeat seconds of a task: 0, or a number of seconds that divides the minute.
     */
    protected function repeatSeconds(Arguments $arguments, RecordType $type, mixed $value): int
    {
        if (! is_int($value) || ! in_array($value, self::REPEAT_SECONDS, true)) {
            throw $arguments->invalid(argument: 'repeat_seconds', expected: 'a whole number of seconds that divides the minute', accepted: '0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30', example: $this->example($type));
        }

        return $value;
    }

    /**
     * Read a source argument that is text.
     */
    protected function text(Arguments $arguments, RecordType $type, string $argument, mixed $value): string
    {
        if (! is_string($value)) {
            throw $arguments->invalid(argument: $argument, expected: 'a string', accepted: 'a string', example: $this->example($type));
        }

        return $value;
    }

    /**
     * Get the types one group id of the type covers: a job's attempts and its dispatch share one.
     *
     * @return non-empty-list<RecordType>
     */
    protected function types(RecordType $type): array
    {
        return match ($type) {
            RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB => [RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB],
            default => [$type],
        };
    }

    /**
     * Read the types that hold each candidate: records, first and last start, and the group label of the latest record.
     *
     * Only the types the id covers count: a command and a job with one name share a hash, but not a group.
     *
     * @param  list<array{group: string, input: string, assumption: string|null}>  $candidates
     * @param  non-empty-list<RecordType>  $types
     * @return list<array{group: string, type: string, records: int, first_started_at: float, last_started_at: float, label: string}>
     */
    protected function held(SQLite3 $connection, array $candidates, array $types): array
    {
        $held = [];

        foreach ($candidates as $candidate) {
            foreach ($types as $type) {
                $bindings = [
                    'group' => $candidate['group'],
                    'type' => $type->value,
                ];
                $span = Stored::rows($connection, 'SELECT count(*) AS records, min(started_at) AS first, max(started_at) AS last FROM records WHERE group_hash = :group AND type = :type', $bindings)[0];

                $records = Cell::integer($span['records']);

                if ($records === 0) {
                    continue;
                }

                $label = $type->labelColumn();
                $latest = Stored::rows($connection, "SELECT \"{$label}\" AS label FROM {$type->view()} WHERE group_hash = :group ORDER BY started_at DESC, id DESC LIMIT 1", ['group' => $candidate['group']]);

                $held[] = [
                    'group' => $candidate['group'],
                    'type' => $type->value,
                    'records' => $records,
                    'first_started_at' => Cell::float($span['first']),
                    'last_started_at' => Cell::float($span['last']),
                    'label' => $type->groupLabel($latest[0]['label'] ?? null),
                ];
            }
        }

        return $held;
    }

    /**
     * Check the type's recipe against its most recent stored records that carry a group hash.
     *
     * @return array{type: string, check: string, detail: string|null}
     */
    protected function check(SQLite3 $connection, RecordType $type): array
    {
        $rows = Stored::rows($connection, 'SELECT group_hash, data FROM records WHERE type = :type AND group_hash IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT '.self::CHECKED_RECORDS, ['type' => $type->value]);

        if ($rows === []) {
            return $this->notEvaluated($type, 'no_records');
        }

        foreach ($rows as $row) {
            $data = Cell::json($row['data']);
            $fields = is_array($data) ? $data : [];

            if (! Recipe::isWhole($type, $fields)) {
                continue;
            }

            return $this->compare($type, $fields, $row['group_hash']);
        }

        return $this->notEvaluated($type, 'cut');
    }

    /**
     * Compare the hash the recipe gives a stored record's fields with the hash Nightwatch stored, naming the reading that reproduced it.
     *
     * @param  array<string, mixed>  $fields
     * @return array{type: string, check: string, detail: string|null}
     */
    protected function compare(RecordType $type, array $fields, mixed $stored): array
    {
        foreach (Recipe::candidates($type, $fields) as $candidate) {
            if ($candidate['group'] === $stored) {
                return [
                    'type' => $type->value,
                    'check' => RecipeCheck::AGREES->value,
                    'detail' => $candidate['assumption'],
                ];
            }
        }

        return [
            'type' => $type->value,
            'check' => RecipeCheck::DISAGREES->value,
            'detail' => null,
        ];
    }

    /**
     * Get the group the store holds a query under when only the reading its given driver rules out is held.
     *
     * @param  array<string, mixed>  $fields
     * @param  non-empty-list<RecordType>  $types
     */
    protected function ruledOut(SQLite3 $connection, RecordType $type, array $fields, ?string $driver, array $types): ?string
    {
        if ($type !== RecordType::QUERY || $driver === null) {
            return null;
        }

        return $this->held($connection, Recipe::candidates($type, $fields), $types)[0]['group'] ?? null;
    }

    /**
     * Get the note that the store holds the query under the other reading, when the given driver's reading missed.
     *
     * @param  list<array<string, mixed>>  $held
     * @return list<string>
     */
    protected function driverNotes(array $held, ?string $ruledOut): array
    {
        if ($held !== [] || $ruledOut === null) {
            return [];
        }

        return [__('firewatch::messages.fingerprint_driver_note', ['group' => $ruledOut])];
    }

    /**
     * Get the check of a type whose recipe could not be run against a stored record, and why.
     *
     * @return array{type: string, check: string, detail: string|null}
     */
    protected function notEvaluated(RecordType $type, ?string $detail): array
    {
        return [
            'type' => $type->value,
            'check' => RecipeCheck::NOT_EVALUATED->value,
            'detail' => $detail,
        ];
    }

    /**
     * Get the checks of types the store could not be read for.
     *
     * @param  non-empty-list<RecordType>  $types
     * @return list<array{type: string, check: string, detail: string|null}>
     */
    protected function unchecked(array $types): array
    {
        return array_map(fn (RecordType $type) => $this->notEvaluated($type, null), $types);
    }

    /**
     * Get the summary: whether the store holds the id and what the recipe check lets the assistant conclude from a miss.
     *
     * @param  non-empty-list<RecordType>  $types
     * @param  list<array{group: string, input: string, assumption: string|null}>  $candidates
     * @param  list<array<string, mixed>>  $held
     */
    protected function summary(array $types, array $candidates, array $held, RecipeCheck $check): string
    {
        $replace = [
            'group' => implode(' or ', array_column($candidates, 'group')),
            'types' => implode(', ', array_map(fn (RecordType $type) => $type->value, $types)),
        ];

        if ($held === []) {
            return __("firewatch::messages.fingerprint_summary_missed.{$check->value}", $replace);
        }

        $records = array_sum(array_column($held, 'records'));

        return trans_choice('firewatch::messages.fingerprint_summary_held', $records, [
            ...$replace,
            'records' => number_format($records),
            'check' => __("firewatch::messages.fingerprint_check.{$check->value}"),
        ]);
    }

    /**
     * Get what the tool filled in for the arguments: HEAD beside GET, and the timezone of a task that named none.
     *
     * @return list<string>
     */
    protected function notes(Request $request, RecordType $type): array
    {
        $notes = [];
        $methods = $request->get('methods');

        if ($type === RecordType::REQUEST && is_array($methods) && in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true)) {
            $notes[] = __('firewatch::messages.fingerprint_head_note');
        }

        if ($type === RecordType::SCHEDULED_TASK && $request->get('timezone') === null) {
            $notes[] = __('firewatch::messages.fingerprint_timezone_note', ['timezone' => $this->scheduleTimezone()]);
        }

        return $notes;
    }

    /**
     * Get the calls that follow from each held group: its occurrences, and its ranking.
     *
     * @param  list<array<string, mixed>>  $held
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(array $held): array
    {
        $calls = [];

        foreach (array_slice(array_values(array_unique(array_column($held, 'group'))), 0, self::NEXT_GROUPS) as $group) {
            $calls[] = [
                'tool' => 'occurrences',
                'arguments' => ['group' => $group],
                'why' => __('firewatch::messages.fingerprint_next_occurrences'),
            ];
            $calls[] = [
                'tool' => 'rank',
                'arguments' => ['group' => $group],
                'why' => __('firewatch::messages.fingerprint_next_rank'),
            ];
        }

        return $calls;
    }

    /**
     * Get the source arguments the type takes.
     *
     * @return list<string>
     */
    protected function arguments(RecordType $type): array
    {
        return self::ARGUMENTS[$type->value] ?? [];
    }

    /**
     * Get the type's arguments as a refusal lists them, such as "methods, path, domain (optional)".
     */
    protected function accepted(RecordType $type): string
    {
        $arguments = array_map(
            fn (string $argument) => in_array($argument, self::OPTIONAL, true) ? "{$argument} (optional)" : $argument,
            $this->arguments($type),
        );

        return implode(', ', $arguments);
    }

    /**
     * Get a call of the tool for the type, as a refusal shows it: its required arguments with placeholders.
     */
    protected function example(RecordType $type): string
    {
        $required = array_diff($this->arguments($type), self::OPTIONAL);
        $arguments = ['type' => $type->value];

        foreach ($required as $argument) {
            $arguments[$argument] = $argument === 'methods' ? ['GET', 'HEAD'] : "<{$argument}>";
        }

        return $this->call($arguments);
    }
}
