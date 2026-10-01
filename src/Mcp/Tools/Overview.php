<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

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
use Illuminate\Support\Carbon;
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
    use AnswersInEnvelope;

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
     * Answer with how many records the store holds, and how many of them are requests, read in one snapshot.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read(Carbon::now()));
    }

    /**
     * Read the store and put what it holds in the envelope.
     */
    protected function read(Carbon $now): Answer
    {
        $epoch = (float) $now->format('U.u');
        $timezone = config()->string('app.timezone');
        $window = Window::between(null, null, $timezone);

        try {
            [$records, $requests, $oldest, $newest] = $this->reader->snapshot($this->countRecords(...));
        } catch (StoreUnusable $unusable) {
            $empty = Emptiness::of($unusable, $this->configuration->database);

            return new Answer('overview', $epoch, $timezone, $window, $empty->summary(), $empty, [], Coverage::of($unusable));
        }

        if ($records === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);

            return new Answer('overview', $epoch, $timezone, $window, $empty->summary(), $empty, [], new Coverage(CoverageState::EMPTY, records: 0));
        }

        return new Answer(
            'overview',
            $epoch,
            $timezone,
            $window,
            __('firewatch::messages.overview_summary', ['records' => $records, 'requests' => $requests]),
            null,
            ['records' => $records, 'requests' => $requests],
            new Coverage(CoverageState::OK, oldest: $oldest, newest: $newest, records: $records),
        );
    }

    /**
     * Count all records and the requests among them, and find the span they cover.
     *
     * @return array{int, int, float|null, float|null}
     */
    protected function countRecords(SQLite3 $connection): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT count(*), count(*) FILTER (WHERE type = :type), min(started_at), max(started_at) FROM records');

        $statement->bindValue(':type', ExecutionType::REQUEST->value);

        /** @var SQLite3Result $result */
        $result = $statement->execute();

        /** @var array{int, int, float|null, float|null} */
        return $result->fetchArray(SQLITE3_NUM);
    }
}
