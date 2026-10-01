<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
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
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
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
        $epoch = (float) $now->format('U.u');
        $timezone = config()->string('app.timezone');
        $window = Window::read($request, $now, $timezone, $this->name());

        try {
            [$total, $records, $requests, $oldest, $newest] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->countRecords($connection, $window));
        } catch (StoreUnusable $unusable) {
            $empty = Emptiness::of($unusable, $this->configuration->database);

            return new Answer('overview', $epoch, $timezone, $window, $empty->summary(), $empty, [], Coverage::of($unusable));
        }

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);

            return new Answer('overview', $epoch, $timezone, $window, $empty->summary(), $empty, [], new Coverage(CoverageState::EMPTY, records: 0));
        }

        if ($records === 0) {
            $empty = Emptiness::windowEmpty($total);

            return new Answer('overview', $epoch, $timezone, $window, $empty->summary(), $empty, [], new Coverage(CoverageState::OK, oldest: $oldest, newest: $newest, records: $total));
        }

        return new Answer(
            'overview',
            $epoch,
            $timezone,
            $window,
            __('firewatch::messages.overview_summary', ['records' => $records, 'requests' => $requests]),
            null,
            ['records' => $records, 'requests' => $requests],
            new Coverage(CoverageState::OK, oldest: $oldest, newest: $newest, records: $total),
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
