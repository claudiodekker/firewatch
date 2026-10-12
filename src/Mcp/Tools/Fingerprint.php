<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Concerns\BuildsAnswers;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Recipe;
use ClaudioDekker\Firewatch\Mcp\RecipeArguments;
use ClaudioDekker\Firewatch\Mcp\RecipeCheck;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Holdings;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('fingerprint')]
#[Title('Fingerprint')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class Fingerprint extends Tool
{
    use BuildsAnswers {
        schema as formatSchema;
    }

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
        return [
            'type' => $schema->string()->enum(array_keys(RecipeArguments::ARGUMENTS))->required(),
            'methods' => $schema->array()->items($schema->string()),
            'path' => $schema->string(),
            'domain' => $schema->string(),
            'name' => $schema->string(),
            'cron' => $schema->string(),
            'timezone' => $schema->string(),
            'repeat_seconds' => $schema->integer()->enum(RecipeArguments::REPEAT_SECONDS)->default(0),
            'connection' => $schema->string(),
            'sql' => $schema->string(),
            'driver' => $schema->string(),
            'store' => $schema->string(),
            'key' => $schema->string(),
            'host' => $schema->string(),
            'class' => $schema->string(),
            'file' => $schema->string(),
            'line' => $schema->integer()->min(1),
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
        $source = RecipeArguments::read($arguments, $type);
        $fields = $source->fields;
        $driver = $source->driver;
        $types = $this->types($type);

        $frame = $this->frame($now);

        $frame->about($types, Window::none(reason: __('firewatch::messages.fingerprint_window_reason'), timezone: $frame->timezone));

        $read = $frame->read(function (SQLite3 $connection) use ($type, $fields, $driver, $types) {
            $candidates = Recipe::candidates($type, $fields, $driver, $this->sites($connection, $type, $fields));

            return [
                Holdings::read($connection),
                $candidates,
                $this->held($connection, $candidates, $types),
                array_map(fn (RecordType $checked) => $this->check($connection, $checked), $types),
                $this->ruledOut($connection, $type, $fields, $driver, $types),
            ];
        });

        if ($read === null) {
            $empty = $frame->unusable();

            return $frame->answer(
                summary: $empty->summary(),
                empty: $empty,
                result: $this->result($type, Recipe::candidates($type, $fields, $driver), [], $this->unchecked($types)),
                notes: $this->notes($request, $type),
            );
        }

        [$holdings, $candidates, $held, $checks, $ruledOut] = $read;

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
     * Read the type to fingerprint: one of the eleven types whose group source can give.
     */
    protected function type(Arguments $arguments): RecordType
    {
        $accepted = implode(', ', array_keys(RecipeArguments::ARGUMENTS));
        $example = RecipeArguments::example(RecordType::REQUEST);
        $value = $arguments->get('type');
        $named = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($named !== null && ! array_key_exists($named->value, RecipeArguments::ARGUMENTS)) {
            throw Refusal::noRecipe($named->value, $accepted, $example);
        }

        return $arguments->requiredType('one of the eleven types with a recipe', $example);
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
     * Read the sites the store holds exceptions of the class at: the files and codes at the file, which give the readings source cannot.
     *
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    protected function sites(SQLite3 $connection, RecordType $type, array $fields): array
    {
        if ($type !== RecordType::EXCEPTION) {
            return [];
        }

        return Stored::rows($connection, 'SELECT DISTINCT file, code FROM exceptions WHERE class = :class AND (file = :file OR instr(file, :nested) = 1) ORDER BY file, code', [
            'class' => $fields['class'],
            'file' => $fields['file'],
            'nested' => Recipe::nestedPrefix($fields['file']),
        ]);
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
            $notes[] = __('firewatch::messages.fingerprint_timezone_note', ['timezone' => RecipeArguments::scheduleTimezone()]);
        }

        $file = $request->get('file');

        if ($type === RecordType::EXCEPTION && is_string($file) && Recipe::isTemplate($file)) {
            $notes[] = __('firewatch::messages.fingerprint_view_note');
        }

        if ($type === RecordType::EXCEPTION && is_string($file) && Recipe::isVendor($file)) {
            $notes[] = __('firewatch::messages.fingerprint_vendor_note');
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
}
