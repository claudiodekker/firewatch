<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Concerns\BuildsAnswers;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\ExecutionHeader;
use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\JobOutcome;
use ClaudioDekker\Firewatch\Mcp\Lineage;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\TraceRecords;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
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
#[Name('trace')]
#[Title('Trace')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class Trace extends Tool
{
    use BuildsAnswers {
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
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => $schema->string()->description(__('firewatch::messages.trace_id_argument')),
            'job_id' => $schema->string()->description(__('firewatch::messages.trace_job_id_argument')),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
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
     * Read the arguments, then the store, and put the trace, or why there is none, in the answer.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this);
        $traceId = $this->id($arguments, 'trace_id');
        $jobId = $this->id($arguments, 'job_id');
        $this->requireOne($traceId, $jobId);
        $limit = $arguments->limit(['trace_id' => '<trace id>']);

        $frame = $this->frame($now);

        $frame->about([...ExecutionType::records(), RecordType::QUEUED_JOB], Window::none(reason: __('firewatch::messages.trace_window_reason'), timezone: $frame->timezone));

        $found = $frame->read(fn (SQLite3 $connection) => $jobId === null
            ? TraceRecords::ofTrace($connection, (string) $traceId)
            : TraceRecords::ofJob($connection, $jobId));

        if ($found === null || $frame->isEmpty()) {
            return $frame->empty();
        }

        if (! $found->held) {
            throw $this->notFound($traceId, $jobId);
        }

        return $this->trace($frame, $found, $jobId, $limit);
    }

    /**
     * Put the trace in the answer.
     *
     * @param  string|null  $jobId  the job asked for, or null when the trace was
     */
    protected function trace(Frame $frame, TraceRecords $found, ?string $jobId, int $limit): Answer
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

        return $frame->answer(
            summary: $summary,
            result: [
                'trace_id' => $found->trace,
                'job_id' => $jobId,
                'executions' => $executions->rows,
                'jobs' => $jobs,
            ],
            notes: $this->notes($found, $jobs),
            truncated: array_filter([
                $executions->truncation(section: 'executions', how: __('firewatch::messages.trace_executions_how'), matched: count($found->executions)),
            ]),
            next: $this->next($found, $jobId),
        );
    }

    /**
     * Get what is partial about the answer.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<string>
     */
    protected function notes(TraceRecords $found, array $jobs): array
    {
        $outcomes = array_column($jobs, 'outcome');
        $notes = [];

        if (in_array(JobOutcome::PENDING->value, $outcomes, true)) {
            $notes[] = __('firewatch::messages.trace_partial_no_attempts');
        }

        if (Lineage::lacksDispatch($found)) {
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
     * Get the calls that follow from the trace.
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
     * Get the execution with the longest duration, or null when none of them has one.
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
     * Get the call that opens an execution.
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
     * Get what the answer shows of an execution.
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
            'label' => $type->labelOf($execution),
            'started_at' => $execution['started_at'],
            'duration_ms' => Stored::milliseconds($execution['duration']),
            'outcome' => ExecutionHeader::outcome($type, $execution),
        ];
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
     * Read an id, or null for none.
     */
    protected function id(Arguments $arguments, string $argument): ?string
    {
        $value = $arguments->get($argument);

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $name = str_replace('_id', ' id', $argument);

        throw $arguments->invalid(argument: $argument, expected: 'a non-empty string', accepted: "a {$name}", example: "trace({$argument}: \"<{$name}>\")");
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
}
