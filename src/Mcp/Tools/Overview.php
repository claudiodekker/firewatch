<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
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
use SQLite3Result;
use SQLite3Stmt;

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
     * Get the arguments of the tool: the window and the format.
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
     * Answer with how many records the store holds, and how many of them are requests, read in one snapshot.
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
        $structural = BlindSpots::for($types, storeLevel: true);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [[$total, $records, $requests, $oldest, $newest], $facts] = $this->reader->snapshot(fn (SQLite3 $connection) => [$this->countRecords($connection, $window), StoreFacts::read($connection)]);
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

        if ($records === 0) {
            $empty = Emptiness::windowEmpty($total);

            return Answer::empty(tool: 'overview', now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $summary = __('firewatch::messages.overview_summary', [
            'records' => $records,
            'requests' => $requests,
        ]);

        return new Answer(
            tool: 'overview',
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $summary,
            empty: null,
            result: [
                'records' => $records,
                'requests' => $requests,
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
        );
    }

    /**
     * Count all records, those of the window and the requests among them, and find the span the records cover.
     *
     * @return array{int, int, int, float|null, float|null}
     */
    protected function countRecords(SQLite3 $connection, Window $window): array
    {
        $condition = $window->condition();

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("SELECT count(*), count(*) FILTER (WHERE {$condition}), count(*) FILTER (WHERE {$condition} AND type = :type), min(started_at), max(started_at) FROM records");

        $window->bind($statement);
        $statement->bindValue(':type', ExecutionType::REQUEST->value);

        /** @var SQLite3Result $result */
        $result = $statement->execute();

        /** @var array{int, int, int, float|null, float|null} */
        return $result->fetchArray(SQLITE3_NUM);
    }
}
