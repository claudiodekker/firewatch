<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\CallFact;
use ClaudioDekker\Firewatch\Mcp\Concerns\BuildsAnswers;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Continuation;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\GroupSelection;
use ClaudioDekker\Firewatch\Mcp\Listing;
use ClaudioDekker\Firewatch\Mcp\LogLevel;
use ClaudioDekker\Firewatch\Mcp\OccurrenceFilters;
use ClaudioDekker\Firewatch\Mcp\Order;
use ClaudioDekker\Firewatch\Mcp\Outcome;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Scope;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\Mcp\WithheldReason;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('occurrences')]
#[Title('Occurrences')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Occurrences extends Tool
{
    use BuildsAnswers {
        schema as formatSchema;
    }

    /**
     * The rows an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 20;

    /**
     * The most rows an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

    /**
     * The example every refusal of this tool gives.
     */
    protected const EXAMPLE = OccurrenceFilters::EXAMPLE;

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
        return __('firewatch::messages.tools.occurrences');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()->description(__('firewatch::messages.occurrences_group_argument')),
            'type' => $schema->string()->enum(array_column(RecordType::events(), 'value')),
            'execution_id' => $schema->string()->description(__('firewatch::messages.occurrences_execution_id_argument')),
            'trace_id' => $schema->string()->description(__('firewatch::messages.occurrences_trace_id_argument')),
            'job_id' => $schema->string()->description(__('firewatch::messages.occurrences_job_id_argument')),
            'user_id' => $schema->string()->description(__('firewatch::messages.occurrences_user_id_argument')),
            'order' => $schema->string()->enum(Order::class)->default(Order::RECENT->value)->description(__('firewatch::messages.occurrences_order_argument')),
            'method' => $schema->string()->description(__('firewatch::messages.occurrences_method_argument')),
            'status' => $schema->string()->description(__('firewatch::messages.occurrences_status_argument')),
            'outcome' => $schema->string()->enum(Outcome::class)->description(__('firewatch::messages.occurrences_outcome_argument')),
            'level' => $schema->string()->enum(LogLevel::class)->description(__('firewatch::messages.occurrences_level_argument')),
            'slower_than_ms' => $schema->number()->description(__('firewatch::messages.occurrences_slower_than_ms_argument')),
            'at_or_above' => $schema->string()->enum(Percentile::class)->description(__('firewatch::messages.occurrences_at_or_above_argument')),
            'matching' => $this->matchingArgument($schema)->description(__('firewatch::messages.occurrences_matching_argument')),
            ...$this->windowSchema($schema),
            ...$this->scopeSchema($schema),
            'deploy' => $schema->string()->description(__('firewatch::messages.deploy_argument')),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
            'cursor' => $schema->string()->description(__('firewatch::messages.cursor_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the individual records the selectors pick out.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the list in the answer.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this);
        $group = $arguments->group('occurrences(group: "<group id>")');
        $type = $arguments->type('one of the record types', self::EXAMPLE);
        $ids = $this->ids($arguments);

        if ($group === null && $type === null && array_filter($ids) === []) {
            throw Refusal::missing(argument: 'selector', accepted: 'group, type, execution_id, trace_id, job_id or user_id', example: self::EXAMPLE);
        }

        $order = $arguments->choiceOrDefault('order', Order::class, 'one of the orders', 'occurrences(type: "request", order: "slowest")');
        $scope = $arguments->scope('occurrences(type: "query", scope: "between")');
        $limit = $arguments->limit(['type' => 'request']);
        $deploy = $arguments->deploy('occurrences(type: "request", deploy: "v1")');
        $selector = $group !== null ? 'group' : (array_key_first(array_filter($ids)) ?? 'type');
        $filters = OccurrenceFilters::from($arguments);

        if ($group === null || $type !== null) {
            $filters->refuseMisfits($order, $type, $selector);
            $this->refuseScope($scope, $type);
        }

        $continuation = Continuation::read($request, $this->name(), $this->key(...));

        $frame = $this->frame($now);
        $window = $continuation->window($now, $frame->timezone)->scoped($scope);

        $frame->about($type === null ? RecordType::events() : [$type], $window, facts: $ids['user_id'] !== null ? [CallFact::ACTOR] : []);

        $read = $frame->read(fn (SQLite3 $connection) => $this->inspect($connection, $window, $order, $group, $type, $ids, $deploy, $filters, $limit, $selector, $continuation));

        if ($read === null) {
            return $frame->empty();
        }

        $continuation->belongsTo($frame->facts()->meta->createdAt);

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        $hasRows = $read['listed'] !== null && $read['listed']['rows'] !== [];
        $empty = $read['selection']->vacancy($frame, $read['inWindow'], $hasRows, $this->given($ids, $filters, $scope));

        if ($empty !== null) {
            return $frame->empty($empty);
        }

        return $this->listing($continuation, $frame, $window, $order, $group, $ids['user_id'], $limit, $read);
    }

    /**
     * Read the store inside its snapshot.
     *
     * @param  array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}  $ids
     * @param  Continuation<array{value: int|float, id: int}>  $continuation
     * @return array{inWindow: int, selection: GroupSelection, baseline: array{samples: int, needed: int, threshold: int|float|null, durations: list<int|float>|null, percentile: Percentile}|null, listed: array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>}|null, sites: list<array{location: string|null, count: int}>|null}
     */
    protected function inspect(SQLite3 $connection, Window $window, Order $order, ?string $group, ?RecordType $type, array $ids, ?string $deploy, OccurrenceFilters $filters, int $limit, string $selector, Continuation $continuation): array
    {
        $inWindow = $window->count($connection);
        $selection = GroupSelection::resolve($connection, $group, $type, $deploy, "occurrences(group: \"{$group}\")");
        $nothing = [
            'inWindow' => $inWindow,
            'selection' => $selection,
            'baseline' => null,
            'listed' => null,
            'sites' => null,
        ];

        if ($inWindow === 0) {
            return $nothing;
        }

        if (! $selection->isHeld()) {
            return $nothing;
        }

        $resolved = $selection->listed();

        $filters->refuseMisfits($order, $resolved, $selector);
        $this->refuseScope($window->scope(), $resolved);

        $listing = new Listing(
            window: $window,
            order: $order,
            filters: $filters,
            group: $group,
            type: $resolved,
            executionId: $ids['execution_id'],
            traceId: $ids['trace_id'],
            jobId: $ids['job_id'],
            userId: $ids['user_id'],
            deploy: $deploy,
        );
        $baseline = $filters->atOrAbove === null ? null : [
            ...$listing->baseline($connection, $filters->atOrAbove),
            'percentile' => $filters->atOrAbove,
        ];
        $threshold = $baseline['threshold'] ?? null;
        $listed = $listing->rows($connection, $limit, $threshold, $continuation->after());
        $sites = $group !== null && $resolved === RecordType::QUERY ? $listing->callSites($connection, $threshold) : null;

        return [
            ...$nothing,
            'baseline' => $baseline,
            'listed' => $listed,
            'sites' => $sites,
        ];
    }

    /**
     * Put the rows, newest or worst first, in the answer.
     *
     * @param  Continuation<array{value: int|float, id: int}>  $continuation
     * @param  array{baseline: array{samples: int, needed: int, threshold: int|float|null, durations: list<int|float>|null, percentile: Percentile}|null, listed: array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>}|null, sites: list<array{location: string|null, count: int}>|null}  $read
     */
    protected function listing(Continuation $continuation, Frame $frame, Window $window, Order $order, ?string $group, ?string $userId, int $limit, array $read): Answer
    {
        /** @var array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>} $listed */
        $listed = $read['listed'];
        $rows = Rows::bound($listed['rows'], $limit);
        $result = [
            'order' => $order->value,
            'rows' => $rows->rows,
        ];

        if ($read['baseline'] !== null) {
            $result['baseline'] = $this->baseline($read['baseline']);
        }

        if ($read['sites'] !== null) {
            $result['call_sites'] = $read['sites'];
        }

        $count = count($rows->rows);
        $summary = trans_choice('firewatch::messages.occurrences_summary', $count, [
            'count' => $count,
            'order' => $order->value,
        ]);
        $truncated = $continuation->truncated($rows, $listed['keys'], 'rows', 'firewatch::messages.occurrences_cursor_how', $frame->facts()->meta->createdAt, $window, $frame->now);

        return $frame->answer(
            summary: $summary,
            result: $result,
            notes: $this->notes($read['baseline'], $userId),
            truncated: $truncated,
            next: $this->next($window, $group, $listed['rows'][0]['group']),
        );
    }

    /**
     * Get the notes of the answer.
     *
     * @param  array{samples: int, needed: int, threshold: int|float|null, durations: list<int|float>|null, percentile: Percentile}|null  $baseline
     * @return list<string>
     */
    protected function notes(?array $baseline, ?string $userId): array
    {
        $notes = [];

        if ($baseline !== null && $baseline['threshold'] === null) {
            $notes[] = __('firewatch::messages.occurrences_baseline_withheld', [
                'percentile' => $baseline['percentile']->value,
                'have' => $baseline['samples'],
                'needed' => $baseline['needed'],
            ]);
        }

        if ($userId !== null) {
            $notes[] = __('firewatch::messages.occurrences_user_only');
        }

        return $notes;
    }

    /**
     * Get the call that shows how the group of the first row compares with its peers.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Window $window, ?string $group, ?string $first): array
    {
        if ($group !== null || $first === null) {
            return [];
        }

        return [[
            'tool' => 'rank',
            'arguments' => [
                'group' => $first,
                ...$window->arguments(),
                ...$window->scoping(),
            ],
            'why' => __('firewatch::messages.occurrences_next_group'),
        ]];
    }

    /**
     * Get the identifiers and filters the call was given, as the words of an empty answer name them.
     *
     * @param  array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}  $ids
     * @return list<string>
     */
    protected function given(array $ids, OccurrenceFilters $filters, Scope $scope): array
    {
        $given = array_filter([
            ...$ids,
            ...$filters->given(),
            'scope' => $scope === Scope::ALL ? null : $scope->value,
        ], fn (mixed $value) => $value !== null);

        return array_map(fn (string $name, mixed $value) => "{$name}: {$value}", array_keys($given), $given);
    }

    /**
     * Refuse a scope that no record of the type can be in.
     */
    protected function refuseScope(Scope $scope, ?RecordType $type): void
    {
        if ($type !== null && ! $scope->holds($type)) {
            throw Refusal::conflicting(argument: 'scope', with: $type->value, accepted: __('firewatch::messages.argument_values.scope_holding'), example: 'occurrences(type: "query", scope: "between")');
        }
    }

    /**
     * Read the key of the last row a cursor continues after, or null when it is none.
     *
     * @param  array<string, mixed>  $last
     * @return array{value: int|float, id: int}|null
     */
    protected function key(array $last): ?array
    {
        $value = $last['value'] ?? null;
        $id = $last['id'] ?? null;

        if (! (is_int($value) || is_float($value)) || ! is_int($id)) {
            return null;
        }

        return [
            'value' => $value,
            'id' => $id,
        ];
    }

    /**
     * Get the baseline of the answer.
     *
     * @param  array{samples: int, needed: int, threshold: int|float|null, durations: list<int|float>|null, percentile: Percentile}  $baseline
     * @return array<string, mixed>
     */
    protected function baseline(array $baseline): array
    {
        $withheld = null;
        $thresholdMilliseconds = null;

        if ($baseline['threshold'] === null) {
            $withheld = [
                'reason' => WithheldReason::SAMPLE_TOO_SMALL->value,
                'have' => $baseline['samples'],
                'needed' => $baseline['needed'],
            ];
        } else {
            $thresholdMilliseconds = Stored::milliseconds($baseline['threshold']);
        }

        return [
            'percentile' => $baseline['percentile']->value,
            'threshold_ms' => $thresholdMilliseconds,
            'samples' => $baseline['samples'],
            'values_ms' => $baseline['durations'] === null ? null : array_map(Stored::milliseconds(...), $baseline['durations']),
            'withheld' => $withheld,
        ];
    }

    /**
     * Read the identifiers that select records.
     *
     * @return array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}
     */
    protected function ids(Arguments $arguments): array
    {
        return [
            'execution_id' => $this->id($arguments, 'execution_id'),
            'trace_id' => $this->id($arguments, 'trace_id'),
            'job_id' => $this->id($arguments, 'job_id'),
            'user_id' => $this->id($arguments, 'user_id'),
        ];
    }

    /**
     * Read an identifier.
     */
    protected function id(Arguments $arguments, string $argument): ?string
    {
        $value = $arguments->get($argument);

        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw $arguments->invalid(argument: $argument, expected: 'an id of at least one character', accepted: "the `{$argument}` of a record", example: "occurrences({$argument}: \"abc\")");
    }
}
