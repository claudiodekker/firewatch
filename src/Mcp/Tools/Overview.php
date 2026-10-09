<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\BudgetSection;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Detectors\Deadline;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Detectors\Verdict;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\FixedSections;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
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
#[Name('overview')]
#[Title('Overview')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Overview extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

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
        return __('firewatch::messages.tools.overview');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->description(__('firewatch::messages.since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with how many records the store holds and what the detectors find in them.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the store and put what it holds in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::read($request, $now, timezone: $timezone, tool: $this->name());

        $types = RecordType::events();
        $structural = BlindSpots::for($types, actor: true, storeLevel: true);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [[$total, $oldest, $newest], $sections, $budgets, $facts, $judgements] = $this->reader->snapshot(fn (SQLite3 $connection) => [
                $this->countRecords($connection),
                FixedSections::read($connection, $window),
                BudgetSection::read($connection, $window, $this->configuration),
                StoreFacts::read($connection),
                $this->detectors->count($connection, $window, new Deadline($epoch)),
            ]);
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...$structural, ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);

            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return Answer::empty(tool: 'overview', now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $blindSpots = [...$structural, ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return Answer::empty(tool: 'overview', now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        if ($sections->records === 0) {
            $empty = Emptiness::windowEmpty($total);

            return Answer::empty(tool: 'overview', now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        return new Answer(
            tool: 'overview',
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($sections, $budgets, $judgements),
            empty: null,
            result: [
                ...$sections->result(),
                ...$budgets->result(),
                'detectors' => $this->detectorRows($judgements),
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($sections, $budgets, $window),
            truncated: array_filter([$budgets->truncation()]),
            next: $this->next($window, $sections, $judgements),
            cuttable: ['slowest_by_total_time'],
        );
    }

    /**
     * Get the rows of the detector table.
     *
     * @param  list<Judgement>  $judgements
     * @return list<array<string, mixed>>
     */
    protected function detectorRows(array $judgements): array
    {
        $order = [Verdict::FINDINGS->value, Verdict::CLEAN->value, Verdict::NOT_EVALUATED->value];
        $rows = array_map(fn (Judgement $judgement) => $judgement->row(), $judgements);

        usort($rows, fn (array $a, array $b) => array_search($a['verdict'], $order, true) <=> array_search($b['verdict'], $order, true));

        return $rows;
    }

    /**
     * Get the summary: the shapes first, then the figures of the window when they fit beside them.
     *
     * @param  list<Judgement>  $judgements
     */
    protected function summary(FixedSections $sections, BudgetSection $budgets, array $judgements): string
    {
        $shapes = $this->detectorSummary($judgements);

        if ($budgets->exceeded() > 0) {
            $shapes .= ' '.trans_choice('firewatch::messages.overview_budgets_over', $budgets->exceeded(), ['count' => $budgets->exceeded()]);
        }

        $summary = "{$shapes} {$this->figures($sections)}";

        return mb_strlen($summary) > Answer::SUMMARY_CHARACTERS ? $shapes : $summary;
    }

    /**
     * Get the sentence that counts the records of the window and the errors among its requests.
     */
    protected function figures(FixedSections $sections): string
    {
        if ($sections->errorRate['with_status'] === 0) {
            return __('firewatch::messages.overview_summary_no_status', ['records' => $sections->records]);
        }

        return __('firewatch::messages.overview_summary', [
            'records' => $sections->records,
            'server_errors' => $sections->errorRate['server_errors'],
            'client_errors' => $sections->errorRate['client_errors'],
            'with_status' => $sections->errorRate['with_status'],
        ]);
    }

    /**
     * Get the sentence that names the shapes with findings and those not evaluated.
     *
     * @param  list<Judgement>  $judgements
     */
    protected function detectorSummary(array $judgements): string
    {
        $with = array_filter($judgements, fn (Judgement $judgement) => $judgement->verdict === Verdict::FINDINGS);
        $without = array_filter($judgements, fn (Judgement $judgement) => $judgement->verdict === Verdict::NOT_EVALUATED);

        if ($with === [] && $without === []) {
            return trans_choice('firewatch::messages.overview_detectors_clean', count($judgements), ['count' => count($judgements)]);
        }

        $sentences = [];

        if ($with !== []) {
            $sentences[] = __('firewatch::messages.overview_detectors_findings', ['shapes' => implode(', ', array_map(fn (Judgement $judgement) => "{$judgement->detector->value} ({$judgement->total})", $with))]);
        }

        if ($without !== []) {
            $sentences[] = __('firewatch::messages.overview_detectors_not_evaluated', ['shapes' => implode(', ', array_map(fn (Judgement $judgement) => $judgement->detector->value, $without))]);
        }

        return implode(' ', $sentences);
    }

    /**
     * Get what the counts leave out or count differently from the window.
     *
     * @return list<string>
     */
    protected function notes(FixedSections $sections, BudgetSection $budgets, Window $window): array
    {
        $notes = [$budgets->note()];

        if ($sections->unknownTypes() > 0) {
            $notes[] = trans_choice('firewatch::messages.overview_unknown_types', $sections->unknownTypes(), ['count' => $sections->unknownTypes()]);
        }

        if ($window->since() !== null || $window->until() !== null) {
            $notes[] = __('firewatch::messages.overview_directory_unwindowed');
        }

        return $notes;
    }

    /**
     * Get the calls that follow, the first five: the findings of each shape that has some, the ranking of the slowest group's type, and the execution of the window that finished last.
     *
     * @param  list<Judgement>  $judgements
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Window $window, FixedSections $sections, array $judgements): array
    {
        $bounds = $window->arguments();
        $calls = [];

        foreach ($judgements as $judgement) {
            if ($judgement->verdict === Verdict::FINDINGS) {
                $calls[] = [
                    'tool' => 'detect',
                    'arguments' => [
                        'shape' => $judgement->detector->value,
                        ...$bounds,
                    ],
                    'why' => __('firewatch::messages.detect_next_shape'),
                ];
            }
        }

        $slowest = $sections->slowestType();

        if ($slowest !== null) {
            $calls[] = [
                'tool' => 'rank',
                'arguments' => [
                    'type' => $slowest->value,
                    'by' => Measure::TOTAL_DURATION->value,
                    ...$bounds,
                ],
                'why' => __('firewatch::messages.overview_next_rank', ['type' => $slowest->value]),
            ];
        }

        if ($sections->latestExecution !== null) {
            $calls[] = [
                'tool' => 'execution',
                'arguments' => ['execution_id' => $sections->latestExecution],
                'why' => __('firewatch::messages.overview_next_execution'),
            ];
        }

        return array_slice($calls, 0, Answer::LISTED);
    }

    /**
     * Count all records and find the span they cover.
     *
     * @return array{int, float|null, float|null}
     */
    protected function countRecords(SQLite3 $connection): array
    {
        $rows = Stored::rows($connection, 'SELECT count(*) AS total, min(started_at) AS oldest, max(started_at) AS newest FROM records');

        /** @var array{int, float|null, float|null} */
        return array_values($rows[0]);
    }
}
