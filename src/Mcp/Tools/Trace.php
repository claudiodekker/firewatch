<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\ExecutionHeader;
use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\JobOutcome;
use ClaudioDekker\Firewatch\Mcp\Lineage;
use ClaudioDekker\Firewatch\Mcp\LineageState;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TraceRecords;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
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
#[Name('trace')]
#[Title('Trace')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Trace extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The executions an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 50;

    /**
     * The most executions an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

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
        return __('firewatch::messages.tools.trace');
    }

    /**
     * Get the arguments of the tool: the trace or the job to follow, how many executions to list and the format.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => $schema->string()->description(__('firewatch::messages.trace_id_argument')),
            'job_id' => $schema->string()->description(__('firewatch::messages.trace_job_id_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.trace_limit_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the executions of a trace and the lineage of the jobs it dispatched or ran.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the trace, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $traceId = $this->id($request, 'trace_id');
        $jobId = $this->id($request, 'job_id');
        $this->requireOne($traceId, $jobId);
        $limit = $this->limit($request);

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::none(reason: __('firewatch::messages.trace_window_reason'), timezone: $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $types = [...ExecutionType::records(), RecordType::QUEUED_JOB];

        try {
            [$total, $oldest, $newest, $facts, $found] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $traceId, $jobId));
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...BlindSpots::for($types), ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $blindSpots = [...BlindSpots::for($types), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($found === null) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        if (! $found->held) {
            throw $this->notFound($traceId, $jobId);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        return $this->trace(epoch: $epoch, timezone: $timezone, window: $window, coverage: $coverage, blindSpots: $blindSpots, found: $found, jobId: $jobId, limit: $limit);
    }

    /**
     * Put the trace in the envelope: its executions, the lineage of the jobs and what is partial about them.
     *
     * @param  list<array<string, mixed>>  $blindSpots
     * @param  string|null  $jobId  the job asked for, or null when the trace was
     */
    protected function trace(float $epoch, string $timezone, Window $window, Coverage $coverage, array $blindSpots, TraceRecords $found, ?string $jobId, int $limit): Answer
    {
        $executions = Rows::bound(array_map($this->link(...), $found->executions), $limit);
        $jobs = Lineage::jobs($found);

        $counts = [
            'executions' => trans_choice('firewatch::messages.trace_executions_count', count($found->executions)),
            'jobs' => trans_choice('firewatch::messages.trace_jobs_count', count($jobs)),
        ];

        $summary = $jobId === null
            ? __('firewatch::messages.trace_summary', [
                'id' => $found->trace,
                ...$counts,
            ])
            : __('firewatch::messages.trace_job_summary', [
                'id' => $jobId,
                ...$counts,
            ]);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $summary,
            empty: null,
            result: [
                'trace_id' => $found->trace,
                'job_id' => $jobId,
                'executions' => $executions->rows,
                'jobs' => $jobs,
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($found, $jobs),
            truncated: array_filter([
                $executions->truncation(section: 'executions', how: __('firewatch::messages.trace_executions_how'), matched: count($found->executions)),
            ]),
            next: $this->next($found, $jobId),
        );
    }

    /**
     * Get what is partial about the answer: a job seen as one side only, and the records that carry the trace when none of its executions is in the store.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<string>
     */
    protected function notes(TraceRecords $found, array $jobs): array
    {
        $outcomes = array_column($jobs, 'outcome');
        $lineages = array_column($jobs, 'lineage');
        $notes = [];

        if (in_array(JobOutcome::PENDING->value, $outcomes, true)) {
            $notes[] = __('firewatch::messages.trace_partial_no_attempts');
        }

        if (in_array(LineageState::NO_DISPATCH->value, $lineages, true)) {
            $notes[] = __('firewatch::messages.trace_partial_no_dispatch');
        }

        if ($found->carrying !== []) {
            $counts = implode(', ', array_map(fn (string $type, int $records) => "{$records} {$type}", array_keys($found->carrying), $found->carrying));

            $notes[] = __('firewatch::messages.trace_no_execution', [
                'id' => $found->trace,
                'counts' => $counts,
            ]);
        }

        return $notes;
    }

    /**
     * Get the calls that follow from the trace: each execution that failed, then the slowest, and the records that carry the id when no execution is held.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(TraceRecords $found, ?string $jobId): array
    {
        $links = [];

        foreach ($found->executions as $execution) {
            if (! is_string($execution['execution_id'])) {
                continue;
            }

            $type = RecordType::from($execution['type']);
            $links[$execution['execution_id']] = [
                'failed' => Failure::of($type, ExecutionHeader::outcome($type, $execution)),
                'duration' => $execution['duration'],
            ];
        }

        foreach ($found->attempts as $attempt) {
            if (! is_string($attempt['execution_id'])) {
                continue;
            }

            $links[$attempt['execution_id']] ??= [
                'failed' => Failure::of(RecordType::JOB_ATTEMPT, $attempt['status']),
                'duration' => $attempt['duration'],
            ];
        }

        $failed = array_map(strval(...), array_keys(array_filter($links, fn (array $link) => $link['failed'])));
        $slowest = $this->slowest($links);
        $next = array_map(fn (string $id) => $this->execution($id, __('firewatch::messages.trace_next_failed')), $failed);

        if ($slowest !== null && ! in_array($slowest, $failed, true)) {
            $next[] = $this->execution($slowest, __('firewatch::messages.trace_next_slowest'));
        }

        if ($found->executions === []) {
            $next[] = [
                'tool' => 'occurrences',
                'arguments' => $jobId === null ? ['trace_id' => $found->trace] : ['job_id' => $jobId],
                'why' => __('firewatch::messages.trace_next_occurrences'),
            ];
        }

        return array_slice($next, 0, Answer::LISTED);
    }

    /**
     * Get the execution with the longest duration, the first of them on a tie, or null when none of them has a duration.
     *
     * @param  array<string|int, array{failed: bool, duration: mixed}>  $links
     */
    protected function slowest(array $links): ?string
    {
        $slowest = null;
        $longest = null;

        foreach ($links as $id => $link) {
            if (is_numeric($link['duration']) && ($longest === null || $link['duration'] > $longest)) {
                $slowest = (string) $id;
                $longest = $link['duration'];
            }
        }

        return $slowest;
    }

    /**
     * Get the call that opens an execution, and why.
     *
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    protected function execution(string $id, string $why): array
    {
        return [
            'tool' => 'execution',
            'arguments' => ['execution_id' => $id],
            'why' => $why,
        ];
    }

    /**
     * Get what the answer shows of an execution: what it was, when it ran and how it ended.
     *
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    protected function link(array $execution): array
    {
        $type = RecordType::from($execution['type']);

        return [
            'execution_id' => $execution['execution_id'],
            'source' => $execution['source'],
            'label' => ExecutionHeader::label($type, $execution),
            'started_at' => $execution['started_at'],
            'duration_ms' => Stored::milliseconds($execution['duration']),
            'outcome' => ExecutionHeader::outcome($type, $execution),
        ];
    }

    /**
     * Read the store's span and facts, and the records of the trace or the job, in one snapshot.
     *
     * @return array{int, float|null, float|null, StoreFacts, TraceRecords|null}
     */
    protected function load(SQLite3 $connection, ?string $traceId, ?string $jobId): array
    {
        $span = Stored::rows($connection, 'SELECT count(*) AS total, min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];
        $total = is_int($span['total']) ? $span['total'] : 0;
        $facts = StoreFacts::read($connection);

        if ($total === 0) {
            return [$total, $span['oldest'], $span['newest'], $facts, null];
        }

        $found = $jobId === null
            ? TraceRecords::ofTrace($connection, (string) $traceId)
            : TraceRecords::ofJob($connection, $jobId);

        return [$total, $span['oldest'], $span['newest'], $facts, $found];
    }

    /**
     * Get the refusal of the id the store does not hold.
     */
    protected function notFound(?string $traceId, ?string $jobId): Refusal
    {
        return $jobId === null
            ? Refusal::notFound(argument: 'trace_id', id: (string) $traceId, accepted: 'a trace id', example: 'trace(trace_id: "<trace id>")')
            : Refusal::notFound(argument: 'job_id', id: $jobId, accepted: 'a job id', example: 'trace(job_id: "<job id>")');
    }

    /**
     * Read an id, which is a non-empty string, or null for none.
     */
    protected function id(Request $request, string $argument): ?string
    {
        $value = $request->get($argument);

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $name = str_replace('_id', ' id', $argument);

        throw Refusal::invalid(argument: $argument, expected: 'a non-empty string', value: $shown, accepted: "a {$name}", example: "trace({$argument}: \"<{$name}>\")");
    }

    /**
     * Fail unless exactly one of the trace id and the job id was given.
     */
    protected function requireOne(?string $traceId, ?string $jobId): void
    {
        if ($traceId === null && $jobId === null) {
            throw Refusal::missing(argument: 'trace_id', accepted: 'exactly one of `trace_id` or `job_id`', example: 'trace(trace_id: "<trace id>")');
        }

        if ($traceId !== null && $jobId !== null) {
            throw Refusal::conflicting(argument: 'job_id', with: 'trace_id', accepted: 'a call with `trace_id` or with `job_id`, not both', example: 'trace(trace_id: "<trace id>")');
        }
    }

    /**
     * Read the most executions to list, from 1 to 100.
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

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: 'trace(trace_id: "<trace id>", limit: '.self::DEFAULT_LIMIT.')');
    }
}
