<?php

use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Date;

const HRM_AT = 1790776000.0;

const HRM_REMOVED_AT = HRM_AT + 600;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(HRM_REMOVED_AT));
});

/**
 * Remove history the way a person does, through the clear command.
 *
 * @param  array<string, mixed>  $options
 */
function hrmClear(array $options = []): void
{
    test()->artisan('firewatch:clear', ['--force' => true, ...$options])->assertSuccessful();
}

/**
 * Store a request and clear everything, so the store exists and holds nothing.
 */
function hrmClearAll(): void
{
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => HRM_AT])]);
    hrmClear();
}

/**
 * Remove history the way retention does, by storing a request older than the retention age and nothing since.
 *
 * @return float the instant the prune removed history through
 */
function hrmPrune(): float
{
    config()->set('firewatch.retention.age', '7d');
    registerFirewatch();

    $started = HRM_REMOVED_AT - 8 * 86400;
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => $started])]);

    return $started;
}

/**
 * Get the judgement of every shape, keyed by shape, over a window.
 *
 * @param  array<string, mixed>  $window
 * @return array<string, array<string, mixed>>
 */
function hrmJudgements(array $window = []): array
{
    $detectors = Envelope::assert(Detect::class, $window)['result']['detectors'];

    return array_column($detectors, null, 'detector');
}

/**
 * Get the reasons the shapes answer with, keyed by shape.
 *
 * @param  array<string, array<string, mixed>>  $judgements
 * @return array<string, string|null>
 */
function hrmReasons(array $judgements): array
{
    return array_map(fn (array $judgement) => $judgement['reason'], $judgements);
}

/**
 * Get every shape that ships, each mapped to the reason.
 *
 * @return array<string, string>
 */
function hrmAll(string $reason): array
{
    return array_fill_keys(app(Detectors::class)->names(), $reason);
}

describe('after history was removed', function () {
    it('answers every shape as outside the coverage for a window that reaches back before the removal', function (string $removal, bool $bounded) {
        $through = $removal === 'prune' ? hrmPrune() : HRM_REMOVED_AT;

        if ($removal === 'clear') {
            hrmClearAll();
        }

        $judgements = hrmJudgements($bounded ? ['since' => (string) ($through - 1)] : []);

        expect(hrmReasons($judgements))->toBe(hrmAll('outside_coverage'))
            ->and(array_unique(array_column($judgements, 'verdict')))->toBe(['not_evaluated'])
            ->and(array_unique(array_column($judgements, 'examined')))->toBe([0])
            ->and(array_unique(array_column($judgements, 'total')))->toBe([0]);
    })->with([
        'a clear, unbounded' => ['clear', false],
        'a clear, starting before it' => ['clear', true],
        'a prune, unbounded' => ['prune', false],
        'a prune, starting before it' => ['prune', true],
    ]);

    it('answers no_records for a window that starts after the removal', function (string $removal) {
        $through = $removal === 'prune' ? hrmPrune() : HRM_REMOVED_AT;

        if ($removal === 'clear') {
            hrmClearAll();
        }

        expect(hrmReasons(hrmJudgements(['since' => (string) ($through + 1)])))->toBe(hrmAll('no_records'));
    })->with(['clear', 'prune']);

    it('treats a window that starts as the removal ends as covered', function () {
        hrmClearAll();

        expect(hrmReasons(hrmJudgements(['since' => (string) HRM_REMOVED_AT])))->toBe(hrmAll('no_records'));
    });

    it('answers no_records, as nothing was removed, in a store that was only created', function () {
        app(Writer::class)->transaction(fn () => null);

        expect(hrmReasons(hrmJudgements()))->toBe(hrmAll('no_records'))
            ->and(hrmReasons(hrmJudgements(['since' => (string) (HRM_AT - 86400)])))->toBe(hrmAll('no_records'));
    });

    it('changes only the shapes that read the type a clear removed', function () {
        ingest([syntheticRecord(RecordType::LOG)->with(['timestamp' => HRM_AT]), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => HRM_AT])]);
        hrmClear(['--type' => 'log']);

        $reasons = hrmReasons(hrmJudgements());

        expect($reasons['error-logs'])->toBe('outside_coverage')
            ->and(array_keys(array_filter($reasons, fn (?string $reason) => $reason === 'outside_coverage')))->toBe(['error-logs']);
    });

    it('carries the reason in the count rows of the overview', function () {
        ingest([syntheticRecord(RecordType::LOG)->with(['timestamp' => HRM_AT]), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => HRM_AT])]);
        hrmClear(['--type' => 'log']);

        $rows = array_column(Envelope::assert(Overview::class)['result']['detectors'], null, 'detector');

        expect($rows['error-logs'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'outside_coverage', 'examined' => 0, 'total' => 0])
            ->and(array_map(fn (array $row) => $row['reason'], $rows))->toEqualCanonicalizing(hrmReasons(hrmJudgements()));
    });

    it('does not call a group no match when history left out its records', function () {
        ingest([syntheticRecord(RecordType::LOG)->with(['timestamp' => HRM_AT]), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => HRM_AT, '_group' => md5('/orders')])]);
        hrmClear(['--type' => 'request']);

        $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-routes', 'group' => md5('/orders')]);

        expect($envelope['result']['reason'])->toBe('outside_coverage')
            ->and($envelope['empty'])->toBeNull();
    });

    it('blames the removal, not the dispatcher, when the dispatches were removed and the attempts left', function () {
        ingest([
            syntheticRecord(RecordType::QUEUED_JOB)->inExecution('dispatch-a')->with(['job_id' => 'a', 'timestamp' => HRM_AT, 'duration' => 0]),
            syntheticRecord(RecordType::JOB_ATTEMPT)->inExecution('a-1')->with(['job_id' => 'a', 'attempt' => 1, 'timestamp' => HRM_AT + 1]),
        ]);
        hrmClear(['--type' => 'queued-job']);
        ingest([syntheticRecord(RecordType::JOB_ATTEMPT)->inExecution('b-1')->with(['job_id' => 'b', 'attempt' => 1, 'timestamp' => HRM_REMOVED_AT + 60])]);

        $before = Envelope::assert(Detect::class, ['shape' => 'queue-latency', 'since' => (string) (HRM_AT - 1)]);
        $after = Envelope::assert(Detect::class, ['shape' => 'queue-latency', 'since' => (string) (HRM_REMOVED_AT + 1)]);

        expect($before['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'outside_coverage', 'examined' => 0, 'total' => 0])
            ->and($before['result']['saw']['attempts_without_dispatch'])->toBe(2)
            ->and($after['result']['reason'])->toBe('prerequisite_missing');
    });
});
