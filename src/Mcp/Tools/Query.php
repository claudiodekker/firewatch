<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\ErrorCode;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\QueryStop;
use ClaudioDekker\Firewatch\Sql\SqlFailure;
use ClaudioDekker\Firewatch\Sql\SqlRows;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
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
#[Name('query')]
#[Title('Query')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Query extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The rows an answer holds when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 50;

    /**
     * The most rows an answer holds.
     */
    protected const MAXIMUM_LIMIT = 500;

    /**
     * A statement that runs on any store, for the examples of a refusal.
     */
    protected const EXAMPLE = 'query(sql: "SELECT type, count(*) FROM records GROUP BY type")';

    /**
     * The statements the tool accepts, as a refusal names them.
     */
    protected const ACCEPTED = 'one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement';

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
        protected SqlRunner $runner,
        protected Availability $availability,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.query');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()->description(__('firewatch::messages.query_sql_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.query_limit_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the rows of the assistant's own read-only statement, as stored.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Check that the SQL child can run, read the arguments, check the store, run the statement in the child, and put its rows in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $reason = $this->availability->reason();

        if ($reason !== null) {
            throw Refusal::sql(SqlFailure::unavailable($reason));
        }

        $sql = $this->sql($request);
        $limit = $this->limit($request);

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::none(reason: __('firewatch::messages.query_window_reason'), timezone: $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            $this->reader->ensureUsable();

            $rows = $this->runner->run($sql, $limit);
        } catch (StoreUnusable $unusable) {
            $blindSpots = $this->conditions->for(null, [], $window);
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, [], History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        } catch (SqlFailure $failure) {
            if ($failure->error === ErrorCode::FAILED) {
                report($failure);
            }

            throw Refusal::sql($failure);
        }

        return $this->rows(rows: $rows, sql: $sql, limit: $limit, epoch: $epoch, timezone: $timezone, window: $window, retention: $retention);
    }

    /**
     * Put the rows in the envelope, with the coverage and blind spots of the types the statement read.
     *
     * @param  array{int|null, int|null}  $retention
     */
    protected function rows(SqlRows $rows, string $sql, int $limit, float $epoch, string $timezone, Window $window, array $retention): Answer
    {
        $types = $rows->typesRead;

        try {
            [$total, $oldest, $newest, $facts] = $this->reader->snapshot($this->span(...));
            $history = History::of($facts->meta, $types, ...$retention);
            $coverage = new Coverage($total === 0 ? CoverageState::EMPTY : CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);
        } catch (StoreUnusable $unusable) {
            $facts = null;
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));
        }

        $blindSpots = [...BlindSpots::for($types), ...$this->conditions->for($facts, $types, $window)];
        $empty = $rows->rows === [] ? Emptiness::noRows() : null;

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $empty === null ? $this->summary($rows) : $empty->message,
            empty: $empty,
            result: [
                'columns' => $rows->columns,
                'rows' => $rows->rows,
                'stop' => $rows->stop->value,
                'elapsed_ms' => $rows->elapsedMilliseconds,
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($rows),
            truncated: $rows->stop === QueryStop::COMPLETE ? [] : [$this->truncation($rows)],
            next: $rows->stop === QueryStop::LIMIT && $limit < self::MAXIMUM_LIMIT ? [$this->more($sql)] : [],
            cuttable: ['rows'],
            capHow: __('firewatch::messages.query_cap_how'),
        );
    }

    /**
     * Get the summary of the rows the statement returned.
     */
    protected function summary(SqlRows $rows): string
    {
        $key = match (true) {
            $rows->stop->isAbnormal() => 'query_summary_partial',
            $rows->stop === QueryStop::COMPLETE => 'query_summary',
            default => 'query_summary_more',
        };

        return __("firewatch::messages.{$key}", [
            'rows' => trans_choice('firewatch::messages.query_rows_count', count($rows->rows)),
            'columns' => trans_choice('firewatch::messages.query_columns_count', count($rows->columns)),
            'shown' => count($rows->rows),
        ]);
    }

    /**
     * Get the notes of the rows: the raw values, and for partial rows that they are unordered and why the statement stopped.
     *
     * @return list<string>
     */
    protected function notes(SqlRows $rows): array
    {
        $notes = [__('firewatch::messages.query_raw_values')];

        if (! $rows->stop->isAbnormal()) {
            return $notes;
        }

        $notes[] = __('firewatch::messages.query_partial_note');

        if ($rows->detail === null) {
            return $notes;
        }

        if ($rows->stop !== QueryStop::ABORTED) {
            return [...$notes, mb_substr($rows->detail, 0, Refusal::SQL_MESSAGE_CHARACTERS)];
        }

        $stderr = preg_replace('/\s+/', ' ', $rows->detail);

        return [...$notes, __('firewatch::messages.aborted_stderr', ['stderr' => $stderr])];
    }

    /**
     * Get the `truncated` entry of rows the limit, the row budget or an abnormal stop cut.
     *
     * @return array{section: string, shown: int, matched: int|null, reason: string, how: string}
     */
    protected function truncation(SqlRows $rows): array
    {
        [$reason, $how] = match (true) {
            $rows->stop === QueryStop::LIMIT => [TruncationReason::LIMIT, 'query_limit_how'],
            $rows->stop === QueryStop::BUDGET => [TruncationReason::SIZE, 'query_budget_how'],
            default => [TruncationReason::PARTIAL, 'query_partial_how'],
        };

        return [
            'section' => 'rows',
            'shown' => count($rows->rows),
            'matched' => null,
            'reason' => $reason->value,
            'how' => __("firewatch::messages.{$how}"),
        ];
    }

    /**
     * Get the call that runs the same statement with the most rows an answer holds.
     *
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    protected function more(string $sql): array
    {
        return [
            'tool' => $this->name(),
            'arguments' => [
                'sql' => $sql,
                'limit' => self::MAXIMUM_LIMIT,
            ],
            'why' => __('firewatch::messages.query_next_more'),
        ];
    }

    /**
     * Read the store's span and facts in one snapshot.
     *
     * @return array{int, float|null, float|null, StoreFacts}
     */
    protected function span(SQLite3 $connection): array
    {
        $span = Stored::rows($connection, 'SELECT count(*) AS total, min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];
        $total = is_int($span['total']) ? $span['total'] : 0;
        $facts = StoreFacts::read($connection);

        return [$total, $span['oldest'], $span['newest'], $facts];
    }

    /**
     * Read the statement, refusing one that is missing or empty.
     */
    protected function sql(Request $request): string
    {
        $value = $request->get('sql');

        if ($value === null || $value === '') {
            throw Refusal::missing(argument: 'sql', accepted: self::ACCEPTED, example: self::EXAMPLE);
        }

        if (is_string($value)) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'sql', expected: 'a string', value: $shown, accepted: self::ACCEPTED, example: self::EXAMPLE);
    }

    /**
     * Read the most rows to return.
     *
     * @return int<1, 500>
     */
    protected function limit(Request $request): int
    {
        $value = $request->get('limit');

        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (is_int($value) && $value >= 1 && $value <= self::MAXIMUM_LIMIT) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: 'query(sql: "SELECT * FROM requests", limit: '.self::DEFAULT_LIMIT.')');
    }
}
