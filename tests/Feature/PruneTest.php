<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\Pruner;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreFailure;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

const PRUNE_NOW = '2026-09-30 14:00:00';
const PRUNE_CUTOFF = 1790172000.0;

/**
 * @return list<float|null>
 */
function startedAts(): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) {
        $result = $connection->query('SELECT started_at FROM records ORDER BY id');
        $startedAts = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $startedAts[] = $row[0];
        }

        return $startedAts;
    });
}

/**
 * @return array<string, string>
 */
function pruneMeta(): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) {
        $result = $connection->query('SELECT key, value FROM meta');
        $meta = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $meta[$row[0]] = $row[1];
        }

        return $meta;
    });
}

function tableCount(string $table): int
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => $connection->querySingle("SELECT count(*) FROM {$table}"));
}

/**
 * @param  list<float>  $timestamps
 */
function requestsStartedAt(array $timestamps): void
{
    ingest(array_map(fn (float $timestamp) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => $timestamp]), $timestamps));
}

function withRetention(int $records): void
{
    config()->set('firewatch.retention.records', $records);

    registerFirewatch();
}

function withSmallPasses(int $chunk, int $transactions): void
{
    app()->instance(Pruner::class, new class(app(Writer::class), app(Reader::class), app(Configuration::class), $chunk, $transactions) extends Pruner
    {
        public function __construct(Writer $writer, Reader $reader, Configuration $configuration, protected int $chunkRows, protected int $passTransactions)
        {
            parent::__construct($writer, $reader, $configuration);
        }

        protected function chunkRows(): int
        {
            return $this->chunkRows;
        }

        protected function passTransactions(): int
        {
            return $this->passTransactions;
        }
    });

    registerFirewatch();
}

beforeEach(function () {
    $this->travelTo(PRUNE_NOW);
    config()->set('firewatch.retention.age', '7d');
    registerFirewatch();
});

describe('by age', function () {
    it('removes what started more than the retention age ago and keeps what is just inside it, by event time', function () {
        requestsStartedAt([PRUNE_CUTOFF - 1, PRUNE_CUTOFF, PRUNE_CUTOFF + 1, PRUNE_CUTOFF - 100]);

        expect(startedAts())->toBe([PRUNE_CUTOFF, PRUNE_CUTOFF + 1]);
    });

    it('records through which instant history was removed, and why', function () {
        requestsStartedAt([PRUNE_CUTOFF - 100, PRUNE_CUTOFF - 1, PRUNE_CUTOFF + 1]);

        expect(pruneMeta())->toMatchArray(['pruned_through' => '1790171999.000000', 'pruned_by' => 'age']);
    });

    it('states in answers the history that was removed', function () {
        $this->travelTo('2026-09-01 00:00:00');
        app(Writer::class)->transaction(fn () => null);
        $this->travelTo(PRUNE_NOW);
        requestsStartedAt([PRUNE_CUTOFF - 1, PRUNE_CUTOFF + 1]);

        $envelope = Envelope::assert(Overview::class);

        expect($envelope['coverage']['history'])->toMatchArray(['from' => 1790171999.0, 'reason' => 'pruned-age']);
    });

    it('writes no marker when it removes nothing', function () {
        requestsStartedAt([PRUNE_CUTOFF + 1]);

        expect(pruneMeta())->not->toHaveKeys(['pruned_through', 'pruned_by']);
    });

    it('never moves the instant history was removed through back', function () {
        requestsStartedAt([PRUNE_CUTOFF - 1]);
        $this->travelTo('2026-09-30 14:02:00');
        requestsStartedAt([PRUNE_CUTOFF - 5000]);

        expect(startedAts())->toBe([])
            ->and(pruneMeta()['pruned_through'])->toBe('1790171999.000000');
    });

    it('removes the records of unknown start below the last record it removes, and leaves them when it removes none', function () {
        ingest([
            ['t' => 'request', 'v' => 1, 'timestamp' => 'soon'],
            syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF - 1]),
            syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF + 1]),
            ['t' => 'request', 'v' => 1, 'timestamp' => 'later'],
        ]);

        expect(startedAts())->toBe([PRUNE_CUTOFF + 1, null]);
    });

    it('keeps records of unknown start when nothing is removed', function () {
        ingest([['t' => 'request', 'v' => 1, 'timestamp' => 'soon'], syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF + 1])]);

        expect(startedAts())->toBe([null, PRUNE_CUTOFF + 1]);
    });

    it('removes users and drift last seen before the cutoff', function () {
        ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1790776800.0]), ['t' => 'mystery', 'v' => 1, 'timestamp' => PRUNE_CUTOFF + 1]]);
        $before = [tableCount('users'), tableCount('drift')];
        $this->travelTo('2026-10-08 14:00:01');

        requestsStartedAt([1791468001.0]);

        expect($before)->toBe([1, 1])
            ->and([tableCount('users'), tableCount('drift')])->toBe([0, 0]);
    });

    it('keeps users and drift last seen just inside the retention age', function () {
        ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1790776800.0]), ['t' => 'mystery', 'v' => 1, 'timestamp' => PRUNE_CUTOFF + 1]]);
        $this->travelTo('2026-10-07 13:59:59');

        requestsStartedAt([1791381599.0]);

        expect([tableCount('users'), tableCount('drift')])->toBe([1, 1]);
    });
});

describe('by record count', function () {
    it('removes nothing at the cap and trims to 90% of it one record over', function () {
        withRetention(10);
        requestsStartedAt(range(1790776701, 1790776710));
        expect(startedAts())->toHaveCount(10);

        $this->travelTo('2026-09-30 14:01:01');
        requestsStartedAt([1790776800.0]);

        expect(startedAts())->toBe([...range(1790776703.0, 1790776710.0), 1790776800.0])
            ->and(pruneMeta())->toMatchArray(['pruned_through' => '1790776702.000000', 'pruned_by' => 'cap']);
    });

    it('counts records of unknown start, and leaves them when it trims the oldest known ones above them', function () {
        withRetention(4);
        requestsStartedAt([1790776701, 1790776702, 1790776703]);
        $this->travelTo('2026-09-30 14:01:01');
        ingest([['t' => 'request', 'v' => 1, 'timestamp' => 'soon'], ['t' => 'request', 'v' => 1, 'timestamp' => 'later']]);

        expect(startedAts())->toBe([1790776703.0, null, null]);
    });
});

describe('the pass', function () {
    it('runs at most once a minute, across processes, and exactly one of two claimants wins', function () {
        withRetention(2);
        requestsStartedAt([1790776701, 1790776702, 1790776703]);
        expect(startedAts())->toBe([1790776703.0]);

        requestsStartedAt([1790776704, 1790776705, 1790776706]);
        expect(startedAts())->toHaveCount(4);

        // A second process that read the last claim before the first took it still loses the claim.
        $stale = new class(app(Writer::class), app(Reader::class), app(Configuration::class)) extends Pruner
        {
            protected function lastClaim(): ?float
            {
                return null;
            }
        };
        $stale->run();
        expect(startedAts())->toHaveCount(4);

        $this->travelTo('2026-09-30 14:00:59');
        $stale->run();
        expect(startedAts())->toHaveCount(4);

        $this->travelTo('2026-09-30 14:01:01');
        app(Pruner::class)->run();
        expect(startedAts())->toBe([1790776706.0]);
    });

    it('removes at most one chunk at a time and as many chunks as a pass has', function () {
        withSmallPasses(chunk: 2, transactions: 2);
        requestsStartedAt(range(1789000001, 1789000007));

        expect(startedAts())->toBe([1789000005.0, 1789000006.0, 1789000007.0]);

        $this->travelTo('2026-09-30 14:01:01');
        requestsStartedAt([1790776800.0]);

        expect(startedAts())->toBe([1790776800.0]);
    });

    it('removes the oldest records first, by event time then id', function () {
        withSmallPasses(chunk: 3, transactions: 1);
        requestsStartedAt([1789000005, 1789000001, 1789000003, 1789000002, 1789000004]);

        expect(startedAts())->toBe([1789000005.0, 1789000004.0]);
    });

    it('starts no pass for a batch that failed', function () {
        app(Writer::class)->transaction(fn () => null);
        $writer = new class(app(Configuration::class)) extends Writer
        {
            public int $transactions = 0;

            public function transaction(Closure $callback): mixed
            {
                $this->transactions++;

                throw new StoreFailure(FailureKind::BUSY, 'busy');
            }
        };
        app()->instance(Writer::class, $writer);
        registerFirewatch();

        requestsStartedAt([PRUNE_CUTOFF - 1]);

        expect($writer->transactions)->toBe(1);
    });

    it('stops silently when a step finds the store busy', function () {
        config()->set('firewatch.busy_timeout', 20);
        registerFirewatch();
        requestsStartedAt([PRUNE_CUTOFF + 1]);
        $this->travelTo('2026-09-30 14:02:00');
        $connection = new SQLite3(app(Configuration::class)->database);
        $connection->busyTimeout(20);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        app(Pruner::class)->run();
        $connection->exec('ROLLBACK');

        expect(file_exists(dirname(app(Configuration::class)->database).'/failures.jsonl'))->toBeFalse();
    });

    it('reports a step that fails for another reason, never throws it, and keeps the batch', function () {
        app()->instance(Pruner::class, new class(app(Writer::class), app(Reader::class), app(Configuration::class)) extends Pruner
        {
            protected function prune(float $now): void
            {
                throw new RuntimeException('the pass broke');
            }
        });
        registerFirewatch();

        requestsStartedAt([PRUNE_CUTOFF + 1]);

        $lines = array_map(fn (string $line) => json_decode($line, associative: true), file(dirname(app(Configuration::class)->database).'/failures.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        expect(startedAts())->toBe([PRUNE_CUTOFF + 1])
            ->and($lines)->toHaveCount(1)
            ->and($lines[0])->toMatchArray(['kind' => 'other', 'dropped' => 0]);
    });
});
