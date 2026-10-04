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
use ClaudioDekker\Firewatch\Mcp\Detectors\Detector;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Detectors\Reason;
use ClaudioDekker\Firewatch\Mcp\Detectors\Verdict;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
use ClaudioDekker\Firewatch\Mcp\Window;
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
#[Name('detect')]
#[Title('Detect')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Detect extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The findings an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 20;

    /**
     * The most findings an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

    /**
     * What a group id is, as the refusal of a malformed one says it.
     */
    protected const GROUP_ID_DESCRIPTION = 'a 32-character lowercase hex group id';

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
        protected Detectors $detectors,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.detect');
    }

    /**
     * Get the arguments of the tool: the shape and what restricts it, the window, the limit and the format.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shape' => $schema->string()->enum($this->detectors->names())->description(__('firewatch::messages.detect_shape_argument')),
            'threshold' => $schema->number()->description(__('firewatch::messages.detect_threshold_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.detect_group_argument')),
            'since' => $schema->string()->description(__('firewatch::messages.since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.detect_limit_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the verdict of one shape, or of every shape that ships, with the findings that back it.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the judgements in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $shape = $this->shape($request);
        $threshold = $this->threshold($request, $shape);
        $group = $this->group($request, $shape);
        $limit = $this->limit($request);

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::read($request, $now, timezone: $timezone, tool: $this->name());

        $detectors = $shape === null ? $this->detectors->all() : [$shape];
        $types = array_values(array_unique(array_merge(...array_map(fn (Detector $detector) => $detector->types(), $detectors)), SORT_REGULAR));
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [[$total, $inWindow, $oldest, $newest], $facts, $judgements] = $this->reader->snapshot(fn (SQLite3 $connection) => [
                $this->count($connection, $window),
                StoreFacts::read($connection),
                array_map(fn (Detector $detector) => $detector->judge($connection, $window, $threshold, $group, $limit), $detectors),
            ]);
        } catch (StoreUnusable $unusable) {
            $judgements = array_map(fn (Detector $detector) => Judgement::notEvaluated($detector->name(), $detector->threshold()?->describe($threshold), Reason::STORE_UNAVAILABLE), $detectors);
            $blindSpots = [...BlindSpots::for($types, actor: true), ...$this->conditions->for(null, $types, $window)];
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return $this->answerFor(
                shape: $shape,
                judgements: $judgements,
                epoch: $epoch,
                timezone: $timezone,
                window: $window,
                empty: Emptiness::of($unusable, $this->configuration->database),
                coverage: $coverage,
                blindSpots: $blindSpots,
            );
        }

        $blindSpots = [...BlindSpots::for($types, actor: true), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        $coverage = $total === 0
            ? new Coverage(CoverageState::EMPTY, $types, $history, records: 0)
            : new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        $empty = match (true) {
            $total === 0 => Emptiness::storeEmpty($this->configuration->database),
            $inWindow === 0 => Emptiness::windowEmpty($total),
            $group !== null && $judgements[0]->examined === 0 && $judgements[0]->reason !== Reason::OUTSIDE_COVERAGE => Emptiness::noMatch($inWindow, ["group: {$group}"]),
            default => null,
        };

        return $this->answerFor(
            shape: $shape,
            judgements: $judgements,
            epoch: $epoch,
            timezone: $timezone,
            window: $window,
            empty: $empty,
            coverage: $coverage,
            blindSpots: $blindSpots,
        );
    }

    /**
     * Put the judgements of one shape, or of all of them, in the envelope.
     *
     * @param  list<Judgement>  $judgements
     * @param  list<array<string, mixed>>  $blindSpots
     */
    protected function answerFor(?Detector $shape, array $judgements, float $epoch, string $timezone, Window $window, ?Emptiness $empty, Coverage $coverage, array $blindSpots): Answer
    {
        if ($shape === null) {
            return new Answer(
                tool: $this->name(),
                now: $epoch,
                timezone: $timezone,
                window: $window,
                summary: $this->allSummary($judgements),
                empty: $empty,
                result: ['detectors' => array_map(fn (Judgement $judgement) => $judgement->toArray(), $judgements)],
                coverage: $coverage,
                blindSpots: $blindSpots,
                next: $this->shapesNext($judgements),
            );
        }

        $judgement = $judgements[0];
        $cut = count($judgement->findings) < $judgement->total;

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($judgement),
            empty: $empty,
            result: $judgement->toArray(),
            coverage: $coverage,
            blindSpots: $blindSpots,
            truncated: $cut ? [$this->truncation($judgement)] : [],
            next: $judgement->reason === Reason::STORE_UNAVAILABLE ? [] : $this->next($judgement),
        );
    }

    /**
     * Get the fixed summary of one judgement.
     */
    protected function summary(Judgement $judgement): string
    {
        $replace = [
            'detector' => $judgement->detector->value,
            'total' => $judgement->total,
            'examined' => $judgement->examined,
            'input' => __('firewatch::messages.detect_input.'.$judgement->detector->value),
            'reason' => $judgement->reason->value ?? '',
        ];

        return __('firewatch::messages.detect_'.$judgement->verdict->value.'_summary', $replace);
    }

    /**
     * Get the summary of every shape's judgement: the shapes with findings first, then those not evaluated, and that there are no findings only when every shape is clean.
     *
     * @param  list<Judgement>  $judgements
     */
    protected function allSummary(array $judgements): string
    {
        $clean = array_filter($judgements, fn (Judgement $judgement) => $judgement->verdict === Verdict::CLEAN);

        if (count($clean) === count($judgements)) {
            return trans_choice('firewatch::messages.detect_all_clean_summary', count($judgements), ['count' => count($judgements)]);
        }

        $parts = [];

        foreach ([Verdict::FINDINGS, Verdict::NOT_EVALUATED, Verdict::CLEAN] as $verdict) {
            foreach ($judgements as $judgement) {
                if ($judgement->verdict === $verdict) {
                    $parts[] = __('firewatch::messages.detect_part_'.$verdict->value, [
                        'detector' => $judgement->detector->value,
                        'total' => $judgement->total,
                        'reason' => $judgement->reason->value ?? '',
                    ]);
                }
            }
        }

        return __('firewatch::messages.detect_all_summary', ['parts' => implode('; ', $parts)]);
    }

    /**
     * Get the entry that says the findings were cut.
     *
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}
     */
    protected function truncation(Judgement $judgement): array
    {
        return [
            'section' => 'findings',
            'shown' => count($judgement->findings),
            'matched' => $judgement->total,
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.detect_findings_how'),
        ];
    }

    /**
     * Get the calls that follow the worst finding of a shape to its worst or latest execution, its records and its group, and the next findings to their latest executions.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Judgement $judgement): array
    {
        $calls = [];

        foreach ($judgement->findings as $position => $finding) {
            $execution = $finding['worst_execution_id'] ?? $finding['latest_execution_id'];

            if ($execution !== null) {
                $calls[] = [
                    'tool' => 'execution',
                    'arguments' => ['execution_id' => $execution],
                    'why' => __('firewatch::messages.detect_next_execution'),
                ];
            }

            if ($position === 0 && $finding['group'] !== null) {
                $calls[] = [
                    'tool' => 'occurrences',
                    'arguments' => ['group' => $finding['group']],
                    'why' => __('firewatch::messages.detect_next_occurrences'),
                ];
                $calls[] = [
                    'tool' => 'rank',
                    'arguments' => ['group' => $finding['group']],
                    'why' => __('firewatch::messages.detect_next_rank'),
                ];
            }
        }

        return array_slice($calls, 0, Answer::LISTED);
    }

    /**
     * Get the calls that list the findings of each shape that has some.
     *
     * @param  list<Judgement>  $judgements
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function shapesNext(array $judgements): array
    {
        $calls = [];

        foreach ($judgements as $judgement) {
            if ($judgement->verdict === Verdict::FINDINGS) {
                $calls[] = [
                    'tool' => $this->name(),
                    'arguments' => ['shape' => $judgement->detector->value],
                    'why' => __('firewatch::messages.detect_next_shape'),
                ];
            }
        }

        return array_slice($calls, 0, Answer::LISTED);
    }

    /**
     * Read the shape to run, or null for every shape that ships.
     */
    protected function shape(Request $request): ?Detector
    {
        $value = $request->get('shape');

        if ($value === null) {
            return null;
        }

        $names = $this->detectors->names();

        if (is_string($value) && ($detector = $this->detectors->named($value)) !== null) {
            return $detector;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', $names);

        throw Refusal::invalid(argument: 'shape', expected: 'one of the shapes', value: $shown, accepted: $accepted, example: "detect(shape: \"{$names[0]}\")");
    }

    /**
     * Read the threshold in force for a shape, or null for its default; it belongs to one shape that has one.
     */
    protected function threshold(Request $request, ?Detector $shape): int|float|null
    {
        $value = $request->get('threshold');

        if ($value === null) {
            return null;
        }

        $example = $this->example($shape ?? $this->detectors->all()[0], 'threshold');

        if ($shape === null) {
            throw Refusal::conflicting(argument: 'threshold', with: 'all shapes', accepted: 'a call with `shape` naming one shape', example: $example);
        }

        $threshold = $shape->threshold();

        if ($threshold === null) {
            throw Refusal::conflicting(argument: 'threshold', with: 'shape: '.$shape->name()->value, accepted: 'a call without `threshold`', example: "detect(shape: \"{$shape->name()->value}\")");
        }

        return $threshold->read($value, $example);
    }

    /**
     * Read the group to restrict the shape to, which is a 32-character lowercase hex group hash and belongs to one shape.
     */
    protected function group(Request $request, ?Detector $shape): ?string
    {
        $value = $request->get('group');

        if ($value === null) {
            return null;
        }

        if ($shape === null) {
            throw Refusal::conflicting(argument: 'group', with: 'all shapes', accepted: 'a call with `shape` naming one shape', example: 'detect(shape: "'.$this->detectors->names()[0].'", group: "<group id>")');
        }

        if (is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'group', expected: self::GROUP_ID_DESCRIPTION, value: $shown, accepted: self::GROUP_ID_DESCRIPTION, example: "detect(shape: \"{$shape->name()->value}\", group: \"<group id>\")");
    }

    /**
     * Read the most findings to list, from 1 to 100.
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

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: 'detect(shape: "'.$this->detectors->names()[0].'", limit: '.self::DEFAULT_LIMIT.')');
    }

    /**
     * Get a valid call of a shape that passes one of its arguments at the default.
     */
    protected function example(Detector $shape, string $argument): string
    {
        $default = $shape->threshold()->default ?? 0;

        return "detect(shape: \"{$shape->name()->value}\", {$argument}: {$default})";
    }
}
