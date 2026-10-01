<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
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
        try {
            [$records, $requests] = $this->reader->snapshot($this->countRecords(...));
        } catch (StoreUnusable $unusable) {
            return $this->unusable($unusable);
        }

        if ($records === 0) {
            return __('firewatch::messages.store_empty', ['path' => $this->configuration->database]);
        }

        return "- **request**: {$requests}";
    }

    /**
     * Get the line for a store that cannot be read: the no-store empty kind when it is absent, otherwise the unusable store and its reason.
     */
    protected function unusable(StoreUnusable $unusable): string
    {
        $path = $this->configuration->database;

        return match ($unusable->state) {
            StoreState::ABSENT => __('firewatch::messages.no_store', ['path' => $path]),
            StoreState::FOREIGN => __('firewatch::messages.store_unusable.foreign_file', ['path' => $path]),
            StoreState::SCHEMA_MISMATCH => __('firewatch::messages.store_unusable.'.($unusable->found < Schema::VERSION ? 'older_schema' : 'newer_schema'), ['path' => $path, 'found' => $unusable->found, 'expected' => Schema::VERSION]),
            StoreState::UNAVAILABLE => __('firewatch::messages.store_unusable.sqlite_too_old', ['path' => $path, 'version' => $unusable->found, 'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION]),
            StoreState::CORRUPT => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.corrupt')]),
            StoreState::BUSY => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.busy')]),
        };
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
