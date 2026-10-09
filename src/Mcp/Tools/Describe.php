<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Catalogue;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Holdings;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Unit;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;
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
#[Name('describe')]
#[Title('Describe')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Describe extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The most recent records the example values of a column are drawn from.
     */
    protected const SAMPLE_RECORDS = 200;

    /**
     * The most example values a column shows.
     */
    protected const SAMPLES = 5;

    /**
     * The most dropped batches an answer lists, newest first.
     */
    protected const DROPPED_BATCHES = 5;

    /**
     * The most types an answer without a type offers to describe next.
     */
    protected const NEXT_TYPES = 3;

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
        protected Availability $availability,
        protected FailureLog $failures,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.describe');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.describe_type_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with what the store holds and how to query it, or with the columns of one object.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the store in one snapshot and put the shipped catalogue beside what it holds.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $type = $this->type($request);
        $catalogue = new Catalogue;

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::none(reason: __('firewatch::messages.describe_window_reason'), timezone: $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $types = $type === null ? RecordType::events() : [$type];
        $structural = BlindSpots::for($types, actor: $type === RecordType::USER, storeLevel: $type === null);

        try {
            [$holdings, $facts, $samples] = $this->reader->snapshot(fn (SQLite3 $connection) => [
                Holdings::read($connection),
                StoreFacts::read($connection),
                $type === null ? [] : $this->samples($connection, $catalogue, $type),
            ]);
        } catch (StoreUnusable $unusable) {
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));
            $summary = $unusable->state === StoreState::ABSENT ? 'describe_summary_absent' : 'describe_summary_unusable';

            return new Answer(
                tool: $this->name(),
                now: $epoch,
                timezone: $timezone,
                window: $window,
                summary: __("firewatch::messages.{$summary}"),
                empty: $empty,
                result: $type === null ? [...$this->schemaSections($catalogue), ...$this->sqlSection($catalogue)] : $this->columnsSection($catalogue, $type, records: null, samples: []),
                coverage: $coverage,
                blindSpots: [...$structural, ...$this->conditions->for(null, $types, $window)],
            );
        }

        $history = History::of($facts->meta, $types, ...$retention);
        $empty = $holdings->records === 0 ? Emptiness::storeEmpty($this->configuration->database) : null;
        $coverage = $empty === null
            ? new Coverage(CoverageState::OK, $types, $history, oldest: $holdings->oldest, newest: $holdings->newest, records: $holdings->records)
            : new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

        $result = $type === null
            ? $this->storeSections($catalogue, $holdings, $facts)
            : $this->columnsSection($catalogue, $type, records: $holdings->of($type), samples: $samples);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($type, $holdings, $result),
            empty: $empty,
            result: $result,
            coverage: $coverage,
            blindSpots: [...$structural, ...$this->conditions->for($facts, $types, $window)],
            notes: $this->notes($type, $holdings),
            truncated: $type === null ? array_filter([$holdings->truncation()]) : [],
            next: $this->next($type, $holdings, $catalogue),
            cuttable: ['drift', 'dropped_batches', 'deploys'],
        );
    }

    /**
     * Get the sections of the answer without a type for a store that can be read: what it holds, then the catalogue and the SQL tool.
     *
     * @return array<string, mixed>
     */
    protected function storeSections(Catalogue $catalogue, Holdings $holdings, StoreFacts $facts): array
    {
        $version = SQLite3::version()['versionString'];
        $dropped = $this->failures->dropped();
        $batches = array_map(fn (array $batch) => [
            'dropped_at' => $batch['at'],
            'kind' => $batch['kind'],
            'dropped' => $batch['dropped'],
        ], array_slice(array_reverse($dropped), 0, self::DROPPED_BATCHES));

        return [
            'sqlite_version' => $version,
            'file_bytes' => Cell::integer(@filesize($this->configuration->database)),
            'live_bytes' => $holdings->liveBytes,
            'wal_mitigation' => Writer::hasWalResetBug($version),
            'source_capture' => true,
            'last_prune_at' => $facts->meta->pruneClaimedAt,
            'dropped_records' => array_sum(array_column($dropped, 'dropped')),
            ...$holdings->result($facts->meta),
            'drift' => $holdings->drift,
            'dropped_batches' => $batches,
            ...$this->schemaSections($catalogue),
            ...$this->sqlSection($catalogue),
        ];
    }

    /**
     * Get the sections every answer without a type carries: the units, how to read JSON, the join keys, the objects and the raw tables' columns.
     *
     * @return array<string, mixed>
     */
    protected function schemaSections(Catalogue $catalogue): array
    {
        $objects = $catalogue->objects();
        $untyped = array_filter($objects, fn (array $object) => $object['type'] === null);

        return [
            'units' => array_map(fn (Unit $unit) => [
                'unit' => $unit->value,
                'meaning' => $unit->label(),
            ], Unit::cases()),
            'json_access' => __('firewatch::messages.describe_json_access'),
            'join_keys' => __('firewatch::messages.describe_join_keys'),
            'objects' => $objects,
            'tables' => array_merge(...array_map(fn (array $object) => $catalogue->columns($object['object']), $untyped)),
        ];
    }

    /**
     * Get the section of one object: its columns with example values, its group recipe and its example statements.
     *
     * @param  array<string, list<mixed>>  $samples
     * @return array<string, mixed>
     */
    protected function columnsSection(Catalogue $catalogue, RecordType $type, ?int $records, array $samples): array
    {
        $object = Catalogue::object($type);

        return [
            'type' => $type->value,
            'object' => $object,
            'records' => $records,
            'group_recipe' => $this->recipe($type),
            'columns' => $catalogue->columns($object, $samples),
            'examples' => $catalogue->examples($type),
        ];
    }

    /**
     * Get the SQL tool's section: whether it can run, its ceilings, what it may read and call, and the fixed examples.
     *
     * @return array<string, mixed>
     */
    protected function sqlSection(Catalogue $catalogue): array
    {
        $reason = $this->availability->reason();

        return [
            'sql' => [
                'available' => $reason === null,
                'reason' => $reason === null ? null : __("firewatch::messages.sql_unavailable.{$reason->value}"),
                'ceilings' => [
                    'statement_bytes' => Policy::SQL_BYTES,
                    'deadline_ms' => (int) (ChildRunner::DEADLINE_SECONDS * 1000),
                    'row_limit' => Query::MAXIMUM_LIMIT,
                    'row_budget_bytes' => Policy::ROW_BUDGET_BYTES,
                    'output_bytes' => ChildRunner::OUTPUT_CAP_BYTES,
                    'sqlite_heap_mb' => Policy::HEAP_LIMIT_MEBIBYTES,
                    'php_memory_mb' => ChildRunner::MEMORY_LIMIT_MEBIBYTES,
                    'cell_characters' => Policy::CELL_CHARACTERS,
                ],
                'readable' => Policy::READABLE,
                'functions' => Policy::FUNCTIONS,
            ],
            'examples' => $catalogue->examples(),
        ];
    }

    /**
     * Get the distinct recent values of each sampled column, from the object's most recent records.
     *
     * @return array<string, list<mixed>>
     */
    protected function samples(SQLite3 $connection, Catalogue $catalogue, RecordType $type): array
    {
        $object = Catalogue::object($type);
        $columns = $catalogue->sampled($object);
        $order = $type === RecordType::USER ? 'last_seen' : 'started_at';

        $cut = fn (string $column) => "iif(typeof(\"{$column}\") = 'text', substr(\"{$column}\", 1, ".Catalogue::SAMPLE_CHARACTERS."), \"{$column}\") AS \"{$column}\"";
        $select = implode(', ', array_map($cut, $columns));

        $rows = Stored::rows($connection, "SELECT {$select} FROM {$object} ORDER BY {$order} DESC LIMIT ".self::SAMPLE_RECORDS);
        $samples = array_fill_keys($columns, []);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = $row[$column];

                if ($value !== null && count($samples[$column]) < self::SAMPLES && ! in_array($value, $samples[$column], true)) {
                    $samples[$column][] = $value;
                }
            }
        }

        return $samples;
    }

    /**
     * Get the summary: what the store holds, or what the object has.
     *
     * @param  array<string, mixed>  $result
     */
    protected function summary(?RecordType $type, Holdings $holdings, array $result): string
    {
        if ($type === null) {
            $key = $holdings->records === 0 ? 'describe_summary_empty' : 'describe_summary';
            $stored = count(array_filter($result['types'], fn (array $row) => $row['records'] > 0));
            $sql = $result['sql']['available'] ? 'describe_sql_available' : 'describe_sql_unavailable';

            return trans_choice("firewatch::messages.{$key}", $holdings->records, [
                'records' => number_format($holdings->records),
                'types' => $stored,
                'sql' => __("firewatch::messages.{$sql}"),
            ]);
        }

        $key = $holdings->of($type) === 0 ? 'describe_type_summary_empty' : 'describe_type_summary';

        return trans_choice("firewatch::messages.{$key}", $holdings->of($type), [
            'object' => $result['object'],
            'columns' => count($result['columns']),
            'records' => number_format($holdings->of($type)),
        ]);
    }

    /**
     * Get what the answer leaves out or counts differently, and the types stored outside the contract.
     *
     * @return list<string>
     */
    protected function notes(?RecordType $type, Holdings $holdings): array
    {
        if ($type !== null) {
            return [];
        }

        $notes = [__('firewatch::messages.describe_bytes_note')];

        if ($holdings->unknownTypes() !== []) {
            $notes[] = __('firewatch::messages.describe_unknown_types', ['types' => implode(', ', $holdings->unknownTypes())]);
        }

        return $notes;
    }

    /**
     * Get the calls that follow: the columns of the types holding the most records, and the first example statement.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(?RecordType $type, Holdings $holdings, Catalogue $catalogue): array
    {
        if ($holdings->records === 0) {
            return [];
        }

        $calls = [];

        if ($type === null) {
            $held = array_filter(RecordType::events(), fn (RecordType $held) => $holdings->of($held) > 0);
            usort($held, fn (RecordType $a, RecordType $b) => $holdings->of($b) <=> $holdings->of($a));

            foreach (array_slice($held, 0, self::NEXT_TYPES) as $busiest) {
                $calls[] = [
                    'tool' => $this->name(),
                    'arguments' => ['type' => $busiest->value],
                    'why' => __('firewatch::messages.describe_next_type', ['type' => $busiest->value]),
                ];
            }
        } elseif ($this->recipe($type) !== null) {
            $calls[] = [
                'tool' => 'rank',
                'arguments' => ['type' => $type->value],
                'why' => __('firewatch::messages.describe_next_rank'),
            ];
        }

        if ($this->availability->reason() === null) {
            $calls[] = [
                'tool' => 'query',
                'arguments' => ['sql' => $catalogue->examples($type)[0]['sql']],
                'why' => __('firewatch::messages.describe_next_query'),
            ];
        }

        return $calls;
    }

    /**
     * Get the group recipe of a type, or null for log and user, which have no group.
     */
    protected function recipe(RecordType $type): ?string
    {
        $key = "firewatch::messages.describe_recipes.{$type->value}";

        $recipe = trans()->has($key) ? __($key) : null;

        return is_string($recipe) ? $recipe : null;
    }

    /**
     * Read the type to describe: a record type or user.
     */
    protected function type(Request $request): ?RecordType
    {
        $value = $request->get('type');

        if ($value === null) {
            return null;
        }

        $type = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($type === null) {
            $accepted = implode(', ', array_map(fn (RecordType $case) => $case->value, RecordType::cases()));
            $shown = json_encode($value, JSON_THROW_ON_ERROR);

            throw Refusal::invalid(argument: 'type', expected: 'one of the twelve record types or user', value: $shown, accepted: $accepted, example: 'describe(type: "request")');
        }

        return $type;
    }
}
