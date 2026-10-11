<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\CallFact;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detector;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Detectors\Reason;
use ClaudioDekker\Firewatch\Mcp\Detectors\Threshold;
use ClaudioDekker\Firewatch\Mcp\Detectors\Ungrouped;
use ClaudioDekker\Firewatch\Mcp\Detectors\Verdict;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
use ClaudioDekker\Firewatch\Mcp\Window;
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
use LogicException;
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
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shape' => $schema->string()->enum($this->detectors->names())->description(__('firewatch::messages.detect_shape_argument')),
            'threshold' => $schema->number()->description(__('firewatch::messages.detect_threshold_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.detect_group_argument')),
            ...$this->windowSchema($schema),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
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
        $arguments = new Arguments($request, $this);
        $shape = $this->shape($arguments);
        $threshold = $this->threshold($request, $shape);
        $group = $this->group($arguments, $shape);
        $limit = $arguments->limit(['shape' => $this->detectors->names()[0]]);

        $frame = $this->frame($now);
        $window = Window::read($request, $now, timezone: $frame->timezone, tool: $this->name());

        $detectors = $shape === null ? $this->detectors->all() : [$shape];
        $types = array_values(array_unique(array_merge(...array_map(fn (Detector $detector) => $detector->types(), $detectors)), SORT_REGULAR));

        $frame->about($types, $window, facts: [CallFact::ACTOR]);

        $read = $frame->read(fn (SQLite3 $connection, StoreFacts $facts) => [
            $window->count($connection),
            array_map(fn (Detector $detector) => $this->detectors->judge($connection, $facts->meta, $detector, $window, $threshold, $group, $limit), $detectors),
        ]);

        if ($read === null) {
            $judgements = array_map(fn (Detector $detector) => Judgement::notEvaluated($detector->name(), $detector->threshold()?->describe($threshold), Reason::STORE_UNAVAILABLE), $detectors);

            return $this->answerFor($frame, $window, $shape, $judgements, $frame->unusable());
        }

        [$inWindow, $judgements] = $read;

        $empty = $frame->storeEmpty() ?? $frame->vacancy(inWindow: $inWindow, groupHeld: true, hasRows: $group === null || ! $judgements[0]->matchedNothing(), filters: ["group: {$group}"]);

        return $this->answerFor($frame, $window, $shape, $judgements, $empty);
    }

    /**
     * Put the judgements of one shape, or of all of them, in the envelope.
     *
     * @param  list<Judgement>  $judgements
     */
    protected function answerFor(Frame $frame, Window $window, ?Detector $shape, array $judgements, ?Emptiness $empty): Answer
    {
        if ($shape === null) {
            return $frame->answer(
                summary: $this->allSummary($judgements),
                empty: $empty,
                result: ['detectors' => array_map(fn (Judgement $judgement) => $judgement->toArray(), $judgements)],
                next: $this->shapesNext($window, $judgements),
            );
        }

        $judgement = $judgements[0];
        $cut = count($judgement->findings) < $judgement->total;

        return $frame->answer(
            summary: $this->summary($judgement),
            empty: $empty,
            result: $judgement->toArray(),
            truncated: $cut ? [$this->truncation($shape, $judgement)] : [],
            next: $judgement->reason === Reason::STORE_UNAVAILABLE ? [] : $this->next($window, $shape, $judgement),
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
     * Get the summary of every shape's judgement.
     *
     * @param  list<Judgement>  $judgements
     */
    protected function allSummary(array $judgements): string
    {
        $clean = array_filter($judgements, fn (Judgement $judgement) => $judgement->verdict === Verdict::CLEAN);

        if (count($clean) === count($judgements)) {
            return trans_choice('firewatch::messages.detect_all_clean_summary', count($judgements), ['count' => count($judgements)]);
        }

        $shapes = [];

        foreach ($judgements as $judgement) {
            $name = $judgement->detector->value;
            $shape = $judgement->verdict === Verdict::FINDINGS ? "{$name} ({$judgement->total})" : $name;

            $shapes[$judgement->verdict->value][$judgement->reason->value ?? ''][] = $shape;
        }

        $parts = [];

        foreach ([Verdict::FINDINGS, Verdict::NOT_EVALUATED, Verdict::CLEAN] as $verdict) {
            foreach ($shapes[$verdict->value] ?? [] as $reason => $names) {
                $parts[] = __('firewatch::messages.detect_part_'.$verdict->value, [
                    'shapes' => implode(', ', $names),
                    'reason' => $reason,
                ]);
            }
        }

        return __('firewatch::messages.detect_all_summary', ['parts' => implode('; ', $parts)]);
    }

    /**
     * Get the entry that says the findings were cut.
     *
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}
     */
    protected function truncation(Detector $shape, Judgement $judgement): array
    {
        return [
            'section' => 'findings',
            'shown' => count($judgement->findings),
            'matched' => $judgement->total,
            'reason' => TruncationReason::LIMIT->value,
            'how' => __($shape instanceof Ungrouped ? 'firewatch::messages.detect_findings_how_ungrouped' : 'firewatch::messages.detect_findings_how'),
        ];
    }

    /**
     * Get the calls that follow the findings of a shape.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Window $window, Detector $shape, Judgement $judgement): array
    {
        $bounds = $window->arguments();
        $calls = [];

        foreach ($judgement->findings as $position => $finding) {
            $execution = $finding->executionId();

            if ($execution !== null) {
                $calls[] = [
                    'tool' => 'execution',
                    'arguments' => ['execution_id' => $execution],
                    'why' => __('firewatch::messages.detect_next_execution'),
                ];
            }

            if ($position === 0 && $finding->group !== null) {
                $calls[] = [
                    'tool' => 'occurrences',
                    'arguments' => [
                        'group' => $finding->group,
                        ...$bounds,
                    ],
                    'why' => __('firewatch::messages.detect_next_occurrences'),
                ];
                $calls[] = [
                    'tool' => 'rank',
                    'arguments' => [
                        'group' => $finding->group,
                        ...$bounds,
                    ],
                    'why' => __('firewatch::messages.detect_next_rank'),
                ];
            } elseif ($position === 0 && $shape instanceof Ungrouped) {
                $listing = $shape->occurrencesOf($finding);

                $calls[] = [
                    ...$listing,
                    'arguments' => [...$listing['arguments'], ...$bounds],
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
    protected function shapesNext(Window $window, array $judgements): array
    {
        $bounds = $window->arguments();
        $calls = [];

        foreach ($judgements as $judgement) {
            if ($judgement->verdict === Verdict::FINDINGS) {
                $calls[] = [
                    'tool' => $this->name(),
                    'arguments' => [
                        'shape' => $judgement->detector->value,
                        ...$bounds,
                    ],
                    'why' => __('firewatch::messages.detect_next_shape'),
                ];
            }
        }

        return array_slice($calls, 0, Answer::LISTED);
    }

    /**
     * Read the shape to run, or null for every shape that ships.
     */
    protected function shape(Arguments $arguments): ?Detector
    {
        $value = $arguments->get('shape');

        if ($value === null) {
            return null;
        }

        $names = $this->detectors->names();

        if (is_string($value) && ($detector = $this->detectors->named($value)) !== null) {
            return $detector;
        }

        throw $arguments->invalid(argument: 'shape', expected: 'one of the shapes', accepted: implode(', ', $names), example: "detect(shape: \"{$names[0]}\")");
    }

    /**
     * Read the threshold in force for a shape, or null for its default.
     */
    protected function threshold(Request $request, ?Detector $shape): int|float|null
    {
        $value = $request->get('threshold');

        if ($value === null) {
            return null;
        }

        if ($shape === null) {
            throw Refusal::conflicting(argument: 'threshold', with: 'all shapes', accepted: 'a call with `shape` naming one shape', example: $this->thresholdedExample());
        }

        $threshold = $shape->threshold();

        if ($threshold === null) {
            throw Refusal::conflicting(argument: 'threshold', with: 'shape: '.$shape->name()->value, accepted: 'a call without `threshold`', example: "detect(shape: \"{$shape->name()->value}\")");
        }

        return $threshold->read($value, $this->example($shape, $threshold));
    }

    /**
     * Read the group to restrict the shape to.
     */
    protected function group(Arguments $arguments, ?Detector $shape): ?string
    {
        $value = $arguments->get('group');

        if ($value === null) {
            return null;
        }

        if ($shape === null) {
            throw Refusal::conflicting(argument: 'group', with: 'all shapes', accepted: 'a call with `shape` naming one shape', example: 'detect(shape: "'.$this->detectors->names()[0].'", group: "<group id>")');
        }

        if ($shape instanceof Ungrouped) {
            throw Refusal::conflicting(argument: 'group', with: 'shape: '.$shape->name()->value, accepted: 'a call without `group`', example: "detect(shape: \"{$shape->name()->value}\")");
        }

        return $arguments->group("detect(shape: \"{$shape->name()->value}\", group: \"<group id>\")");
    }

    /**
     * Get a valid call of the first shape that has a threshold.
     */
    protected function thresholdedExample(): string
    {
        foreach ($this->detectors->all() as $detector) {
            $threshold = $detector->threshold();

            if ($threshold !== null) {
                return $this->example($detector, $threshold);
            }
        }

        throw new LogicException('No shipped shape has a threshold.');
    }

    /**
     * Get a valid call of a shape that passes its threshold at the default.
     */
    protected function example(Detector $shape, Threshold $threshold): string
    {
        return "detect(shape: \"{$shape->name()->value}\", threshold: {$threshold->default})";
    }
}
