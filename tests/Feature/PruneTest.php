<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Pruner;
use ClaudioDekker\Firewatch\Store\PruneReason;
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

function pruneMarkers(): Markers
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection));
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

        $startedAts = startedAts();

        expect($startedAts)->toBe([PRUNE_CUTOFF, PRUNE_CUTOFF + 1]);
    });

    it('records through which instant history was removed, and why', function () {
        requestsStartedAt([PRUNE_CUTOFF - 100, PRUNE_CUTOFF - 1, PRUNE_CUTOFF + 1]);

        $markers = pruneMarkers();

        expect($markers->prunedThrough)->toBe(1790171999.0)
            ->and($markers->prunedReason)->toBe(PruneReason::AGE);
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

        $markers = pruneMarkers();

        expect($markers->prunedThrough)->toBeNull()
            ->and($markers->prunedReason)->toBeNull();
    });

    it('never moves the instant history was removed through back', function () {
        requestsStartedAt([PRUNE_CUTOFF - 1]);
        $this->travelTo('2026-09-30 14:03:00');
        requestsStartedAt([PRUNE_CUTOFF - 5000]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([])
            ->and(pruneMarkers()->prunedThrough)->toBe(1790171999.0);
    });

    it('removes the records of unknown start below the last record it removes, and leaves them when it removes none', function () {
        ingest([
            ['t' => 'request', 'v' => 1, 'timestamp' => 'soon'],
            syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF - 1]),
            syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF + 1]),
            ['t' => 'request', 'v' => 1, 'timestamp' => 'later'],
        ]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([PRUNE_CUTOFF + 1, null]);
    });

    it('keeps records of unknown start when nothing is removed', function () {
        ingest([['t' => 'request', 'v' => 1, 'timestamp' => 'soon'], syntheticRecord(RecordType::REQUEST)->with(['timestamp' => PRUNE_CUTOFF + 1])]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([null, PRUNE_CUTOFF + 1]);
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
        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(10);

        $this->travelTo('2026-09-30 14:01:01');
        requestsStartedAt([1790776800.0]);

        $markers = pruneMarkers();

        $startedAts = startedAts();

        expect($startedAts)->toBe([...range(1790776703.0, 1790776710.0), 1790776800.0])
            ->and($markers->prunedThrough)->toBe(1790776702.0)
            ->and($markers->prunedReason)->toBe(PruneReason::CAP);
    });

    it('counts records of unknown start, and leaves them when it trims the oldest known ones above them', function () {
        withRetention(4);
        requestsStartedAt([1790776701, 1790776702, 1790776703]);
        $this->travelTo('2026-09-30 14:01:01');
        ingest([['t' => 'request', 'v' => 1, 'timestamp' => 'soon'], ['t' => 'request', 'v' => 1, 'timestamp' => 'later']]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([1790776703.0, null, null]);
    });

    it('trims records of unknown start by arrival once none of known start are left above the cap', function () {
        withRetention(10);
        $counts = [];

        foreach (['14:00:00', '14:01:01', '14:02:02'] as $time) {
            $this->travelTo("2026-09-30 {$time}");
            ingest(array_fill(0, 20, syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'unknown'])));
            $counts[] = count(startedAts());
        }

        expect($counts)->toBe([9, 9, 9])
            ->and(array_column(storeRows('SELECT id FROM records ORDER BY id'), 'id'))->toBe(range(52, 60))
            ->and(pruneMarkers()->prunedThrough)->toBeNull();
    });
});

describe('the pass', function () {
    it('runs at most once a minute, across processes, and exactly one of two claimants wins', function () {
        withRetention(2);
        requestsStartedAt([1790776701, 1790776702, 1790776703]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([1790776703.0]);

        requestsStartedAt([1790776704, 1790776705, 1790776706]);

        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(4);

        // A second process that read the last claim before the first took it still loses the claim.
        $stale = new class(app(Writer::class), app(Reader::class), app(Configuration::class)) extends Pruner
        {
            protected function lastClaim(): ?float
            {
                return null;
            }
        };
        $stale->run();
        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(4);

        $this->travelTo('2026-09-30 14:00:59');
        $stale->run();
        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(4);

        $this->travelTo('2026-09-30 14:01:01');
        app(Pruner::class)->run();
        $startedAts = startedAts();

        expect($startedAts)->toBe([1790776706.0]);
    });

    it('removes at most one chunk at a time and as many chunks as a pass has', function () {
        withSmallPasses(chunk: 2, transactions: 2);
        requestsStartedAt(range(1789000001, 1789000007));

        $startedAts = startedAts();

        expect($startedAts)->toBe([1789000005.0, 1789000006.0, 1789000007.0]);

        $this->travelTo('2026-09-30 14:01:01');
        requestsStartedAt([1790776800.0]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([1790776800.0]);
    });

    it('removes the oldest records first, by event time then id', function () {
        withSmallPasses(chunk: 3, transactions: 1);
        requestsStartedAt([1789000005, 1789000001, 1789000003, 1789000002, 1789000004]);

        $startedAts = startedAts();

        expect($startedAts)->toBe([1789000005.0, 1789000004.0]);
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
        $this->endCapture();
        $this->travelTo('2026-09-30 14:03:00');
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

        $startedAts = startedAts();

        expect($startedAts)->toBe([PRUNE_CUTOFF + 1])
            ->and($lines)->toHaveCount(1)
            ->and($lines[0])->toMatchArray(['kind' => 'other', 'dropped' => 0]);
    });
});

/**
 * @return array{live: int, free: int, total: int}
 */
function storePages(): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) {
        $total = $connection->querySingle('PRAGMA page_count');
        $free = $connection->querySingle('PRAGMA freelist_count');

        return ['live' => $total - $free, 'free' => $free, 'total' => $total];
    });
}

function withBackstop(int $pages, int $chunk = 5, int $transactions = 20, int $reclaim = 1024): void
{
    app()->instance(Pruner::class, new class(app(Writer::class), app(Reader::class), app(Configuration::class), $pages, $chunk, $transactions, $reclaim) extends Pruner
    {
        public function __construct(Writer $writer, Reader $reader, Configuration $configuration, protected int $pages, protected int $chunkRows, protected int $passTransactions, protected int $reclaimPages)
        {
            parent::__construct($writer, $reader, $configuration);
        }

        protected function backstopBytes(): int
        {
            return $this->pages * 4096;
        }

        protected function chunkRows(): int
        {
            return $this->chunkRows;
        }

        protected function passTransactions(): int
        {
            return $this->passTransactions;
        }

        protected function reclaimPages(): int
        {
            return $this->reclaimPages;
        }
    });

    registerFirewatch();
}

it('holds the store to 125% of the backstop on every writer connection', function () {
    $ceiling = app(Writer::class)->maintain(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA max_page_count'));

    expect($ceiling)->toBe(163840);
});

/**
 * Fill the store with requests and get its live pages.
 */
function storeWithRequests(int $count): int
{
    requestsStartedAt(range(1790776001, 1790776000 + $count));
    test()->travelTo('2026-09-30 14:03:00');

    return analyzedLivePages();
}

/**
 * Analyze the store and get its live pages.
 *
 * A pass that deleted optimizes the store, which analyzes it on a connection that saw the deletes; analyzed beforehand, the statistics are already counted.
 */
function analyzedLivePages(): int
{
    app(Writer::class)->maintain(fn (SQLite3 $connection) => $connection->exec('ANALYZE'));

    return storePages()['live'];
}

describe('the size backstop', function () {
    it('leaves a store alone while its live bytes do not exceed the backstop', function () {
        $live = storeWithRequests(200);

        withBackstop($live);
        app(Pruner::class)->run();

        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(200);
    });

    it('trims the oldest records down to 90% of the backstop once live bytes exceed it', function () {
        $live = storeWithRequests(200);

        withBackstop($live - 1, chunk: 10);
        app(Pruner::class)->run();

        $target = (int) floor(0.9 * ($live - 1));
        $startedAts = startedAts();

        expect(storePages()['live'])->toBeLessThanOrEqual($target)
            ->and(storePages()['live'])->toBeGreaterThan($target - 12)
            ->and($startedAts)->not->toContain(1790776001.0)
            ->and(end($startedAts))->toBe(1790776200.0)
            ->and(pruneMarkers()->prunedReason)->toBe(PruneReason::SIZE);
    });

    it('stops as soon as a chunk brings it under, reading the page counts again after each chunk', function () {
        $live = storeWithRequests(200);

        withBackstop($live - 1, chunk: 1, transactions: 200);
        app(Pruner::class)->run();

        $target = (int) floor(0.9 * ($live - 1));

        expect(storePages()['live'])->toBeLessThanOrEqual($target)
            ->and(storePages()['live'])->toBeGreaterThan($target - 3);
    });

    it('trims records of unknown start by arrival once none of known start are left', function () {
        ingest(array_fill(0, 200, syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'unknown'])));
        $this->travelTo('2026-09-30 14:03:00');
        $live = analyzedLivePages();

        withBackstop($live - 1, chunk: 10);
        app(Pruner::class)->run();

        $ids = array_column(storeRows('SELECT id FROM records ORDER BY id'), 'id');

        expect(storePages()['live'])->toBeLessThanOrEqual((int) floor(0.9 * ($live - 1)))
            ->and($ids)->not->toContain(1)
            ->and(end($ids))->toBe(200);
    });

    it('shares the pass transactions with the age and count trims', function () {
        $live = storeWithRequests(200);

        withBackstop(intdiv($live, 2), chunk: 2, transactions: 3);
        app(Pruner::class)->run();

        $startedAts = startedAts();

        expect($startedAts)->toHaveCount(200 - 6);
    });
});

describe('reclamation', function () {
    it('returns freed pages to the file and checkpoints after a pass that deleted', function () {
        requestsStartedAt(range(1790776001, 1790776300));
        $this->travelTo('2026-09-30 14:01:01');
        $before = storePages();

        withBackstop(intdiv($before['live'], 2), chunk: 100);
        requestsStartedAt([1790776301]);
        $after = storePages();

        expect($after['free'])->toBe(0)
            ->and($after['total'])->toBeLessThan($before['total'])
            ->and(filesize(app(Configuration::class)->database))->toBe($after['total'] * 4096);
    });

    it('vacuums at most the reclaim page count at a time', function () {
        requestsStartedAt(range(1790776001, 1790776300));
        $this->travelTo('2026-09-30 14:01:01');
        $before = storePages();

        withBackstop(intdiv($before['live'], 2), chunk: 100, reclaim: 2);
        requestsStartedAt([1790776301]);

        expect(storePages()['free'])->toBeGreaterThan(2);

        $this->travelTo('2026-09-30 14:03:00');
        $free = storePages()['free'];
        app(Pruner::class)->run();

        expect(storePages()['free'])->toBe($free - 2);
    });

    it('does nothing in an idle pass with a freelist no larger than the reclaim count', function () {
        requestsStartedAt(range(1790776001, 1790776300));
        $this->travelTo('2026-09-30 14:01:01');
        $before = storePages();

        withBackstop(intdiv($before['live'], 2), chunk: 100, reclaim: 2);
        requestsStartedAt([1790776301]);
        $free = storePages()['free'];

        withBackstop(intdiv($before['live'], 2), chunk: 100, reclaim: $free);
        $this->travelTo('2026-09-30 14:03:00');
        app(Pruner::class)->run();

        expect(storePages()['free'])->toBe($free);
    });
});

it('drops a batch that would pass the ceiling as a full failure and never throws it', function () {
    app()->instance(Writer::class, new class(app(Configuration::class)) extends Writer
    {
        public const SIZE_BACKSTOP_BYTES = 40 * 4096;
    });
    registerFirewatch();

    requestsStartedAt(range(1790776001, 1790776400));

    $lines = array_map(fn (string $line) => json_decode($line, associative: true), file(dirname(app(Configuration::class)->database).'/failures.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['kind' => 'full'])
        ->and(startedAts())->toBe([]);
});

/**
 * @return list<array<string, mixed>>
 */
function pruneFailures(): array
{
    $path = dirname(app(Configuration::class)->database).'/failures.jsonl';

    return is_file($path) ? array_map(fn (string $line) => json_decode($line, associative: true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

it('trims a full store at once, though the pass of the minute was claimed, so the next batch is taken', function (string $sqliteVersion) {
    app()->instance(Writer::class, new class(app(Configuration::class), $sqliteVersion) extends Writer
    {
        public const SIZE_BACKSTOP_BYTES = 40 * 4096;
    });
    withBackstop(40, chunk: 5);
    $first = 1790776001;

    // The first batch claims the pass of the minute, and the burst after it fills the store.
    while (pruneFailures() === [] && $first < 1790777001) {
        requestsStartedAt(range($first, $first + 4));
        $first += 5;
    }

    requestsStartedAt(range($first, $first + 4));

    $startedAts = startedAts();

    expect(pruneFailures())->toHaveCount(1)
        ->and(pruneFailures()[0])->toMatchArray(['kind' => 'full', 'dropped' => 5])
        ->and($startedAts)->toContain((float) $first + 4)
        ->and($startedAts)->not->toContain(1790776001.0)
        ->and(pruneMarkers()->prunedReason)->toBe(PruneReason::SIZE);
})->with([
    'a writer that closes its connection per batch' => '3.45.1',
    'a writer that keeps its connection' => '3.51.3',
]);
