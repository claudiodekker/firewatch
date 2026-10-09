<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

/**
 * Store one record of every type a few seconds ago, linked as the sensors link them, so that every example finds rows.
 */
function dscxSeed(): void
{
    $now = microtime(true);
    $inside = fn (RecordType $type, float $ago) => syntheticRecord($type)->with(['timestamp' => $now - $ago, 'execution_id' => 'execution', 'trace_id' => 'execution']);

    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => $now - 10, 'trace_id' => 'execution', 'user' => '7']),
        syntheticRecord(RecordType::COMMAND)->with(['timestamp' => $now - 9, 'trace_id' => 'command']),
        syntheticRecord(RecordType::SCHEDULED_TASK)->with(['timestamp' => $now - 8, 'trace_id' => 'task']),
        syntheticRecord(RecordType::QUEUED_JOB)->with(['timestamp' => $now - 7, 'execution_id' => 'execution', 'trace_id' => 'execution', 'job_id' => 'job']),
        syntheticRecord(RecordType::JOB_ATTEMPT)->with(['timestamp' => $now - 6, 'attempt_id' => 'attempt', 'trace_id' => 'execution', 'job_id' => 'job']),
        $inside(RecordType::QUERY, 5),
        $inside(RecordType::EXCEPTION, 4),
        $inside(RecordType::LOG, 3),
        $inside(RecordType::CACHE_EVENT, 2),
        $inside(RecordType::MAIL, 1.5),
        $inside(RecordType::NOTIFICATION, 1),
        $inside(RecordType::OUTGOING_REQUEST, 0.5),
        syntheticRecord(RecordType::USER)->with(['timestamp' => $now - 11, 'id' => '7']),
    ]);
}

it('runs every example a describe answer offers through the SQL tool and gets rows', function (?string $type) {
    dscxSeed();

    $examples = Envelope::assert(Describe::class, $type === null ? [] : ['type' => $type])['result']['examples'];

    expect($examples)->toHaveCount($type === null ? 5 : 3);

    foreach ($examples as $example) {
        $answer = Envelope::assert(Query::class, ['sql' => $example['sql']]);

        expect($answer['result']['stop'])->toBe('complete', $example['sql'])
            ->and($answer['result']['rows'])->not->toBe([], $example['sql']);
    }
})->with([
    'the store' => [null],
    ...array_combine(
        array_map(fn (RecordType $type) => $type->value, RecordType::cases()),
        array_map(fn (RecordType $type) => [$type->value], RecordType::cases()),
    ),
])->group('process');
