<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Response;
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
     * Answer with the store clock and how many requests the store holds.
     */
    public function handle(): Response
    {
        $now = Carbon::now();

        $clock = __('firewatch::messages.store_clock', [
            'time' => $now->format('Y-m-d H:i:s.u'),
            'epoch' => (float) $now->format('U.u'),
        ]);

        $lines = ['## overview', $clock, $this->counts()];

        return Response::text(implode("\n", $lines));
    }

    /**
     * Get the empty-kind line, or the count of requests read in one snapshot.
     */
    protected function counts(): string
    {
        if (! $this->reader->exists()) {
            return __('firewatch::messages.no_store', ['path' => $this->configuration->database]);
        }

        [$records, $requests] = $this->reader->snapshot($this->countRecords(...));

        if ($records === 0) {
            return __('firewatch::messages.store_empty', ['path' => $this->configuration->database]);
        }

        return "- **request**: {$requests}";
    }

    /**
     * Count all records and the requests among them.
     *
     * @return array{int, int}
     */
    protected function countRecords(SQLite3 $connection): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT count(*), count(*) FILTER (WHERE type = :type) FROM records');

        $statement->bindValue(':type', ExecutionType::REQUEST->value);

        /** @var SQLite3Result $result */
        $result = $statement->execute();

        /** @var array{int, int} */
        return $result->fetchArray(SQLITE3_NUM);
    }
}
