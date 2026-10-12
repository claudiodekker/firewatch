<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Arr;

/**
 * Get a record of a group named after its label, with the duration it took, started at the given second, under a deploy or under none.
 */
function byDeployTimed(RecordType $type, string $name, int $microseconds, int $second, ?string $deploy = null): RecordBuilder
{
    $label = $type === RecordType::REQUEST ? 'route_path' : 'name';
    $record = syntheticRecord($type)->with(['_group' => md5($name), $label => $name, 'duration' => $microseconds, 'timestamp' => 1790690400.0 + $second]);

    return $deploy === null ? $record->with(['deploy' => '']) : $record->deploy($deploy);
}

/**
 * Get the blocks of the slowest list as the deploy and the labels of its groups.
 *
 * @param  array<string, mixed>  $arguments
 * @return list<array{string, list<string>}>
 */
function byDeployBlocks(array $arguments = []): array
{
    $slowest = Answers::assert(Overview::class, ['by_deploy' => true, ...$arguments])['result']['slowest_by_total_time'];

    return array_map(fn (array $block) => [$block['deploy'], array_column($block['groups'], 'label')], $slowest);
}

it('lists the slowest groups of each deploy in a block of its own, ordered by when the deploy was first seen, with the average beside the total', function () {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 100, 'v2'),
        byDeployTimed(RecordType::REQUEST, '/orders', 2_000_000, 10, 'v1'),
        byDeployTimed(RecordType::REQUEST, '/orders', 4_000_000, 11, 'v1'),
        byDeployTimed(RecordType::QUERY, 'select 1', 500_000, 12, 'v1'),
    ]);

    $answer = Answers::assert(Overview::class, ['by_deploy' => true]);

    expect($answer['result']['slowest_by_total_time'])->toEqual([
        ['deploy' => 'v1', 'groups' => [
            ['type' => 'request', 'group' => md5('/orders'), 'label' => '/orders', 'occurrences' => 2, 'total_ms' => 6000.0, 'avg_ms' => 3000.0],
            ['type' => 'query', 'group' => md5('select 1'), 'label' => 'select 1', 'occurrences' => 1, 'total_ms' => 500.0, 'avg_ms' => 500.0],
        ]],
        ['deploy' => 'v2', 'groups' => [
            ['type' => 'request', 'group' => md5('/orders'), 'label' => '/orders', 'occurrences' => 1, 'total_ms' => 1000.0, 'avg_ms' => 1000.0],
        ]],
    ])->and($answer['truncated'])->toBe([]);
});

it('keeps the caps of three groups of a type and ten in all in every block', function () {
    $records = [];

    foreach (['v1', 'v2'] as $deploy) {
        foreach ([RecordType::REQUEST, RecordType::COMMAND, RecordType::QUERY, RecordType::QUEUED_JOB] as $position => $type) {
            foreach (range(1, 4) as $group) {
                $records[] = byDeployTimed($type, "{$type->value}-{$group}", ($position + 1) * 1000 + $group, $position, $deploy);
            }
        }
    }

    ingest($records);
    $blocks = byDeployBlocks();

    expect($blocks)->toHaveCount(2)
        ->and(array_map(fn (array $block) => count($block[1]), $blocks))->toBe([10, 10])
        ->and($blocks[1][1][0])->toBe('queued-job-4');
});

it('makes the records of no deploy a block of their own, labelled as rank labels them', function () {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 3_000_000, 10),
        byDeployTimed(RecordType::REQUEST, '/invoices', 1_000_000, 20, 'v1'),
    ]);

    expect(byDeployBlocks())->toBe([
        [__('firewatch::messages.rank_no_deploy'), ['/orders']],
        ['v1', ['/invoices']],
    ]);
});

it('shows only the most recent deploys past the cap, in the order they were first seen, and counts the rest', function () {
    ingest(array_map(fn (int $deploy) => byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, $deploy * 10, "v{$deploy}"), range(1, 7)));

    $answer = Answers::assert(Overview::class, ['by_deploy' => true]);

    expect(array_column($answer['result']['slowest_by_total_time'], 'deploy'))->toBe(['v3', 'v4', 'v5', 'v6', 'v7'])
        ->and($answer['truncated'])->toBe([[
            'section' => 'slowest_by_total_time',
            'shown' => 5,
            'matched' => 7,
            'reason' => 'limit',
            'how' => __('firewatch::messages.overview_deploys_truncated_how'),
        ]]);
});

it('answers byte for byte as without the argument for false, and changes only the slowest list for true', function () {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 10, 'v1'),
        byDeployTimed(RecordType::REQUEST, '/orders', 2_000_000, 20, 'v2'),
    ]);

    $plain = Answers::assert(Overview::class);
    $off = Answers::assert(Overview::class, ['by_deploy' => false]);
    $on = Answers::assert(Overview::class, ['by_deploy' => true]);

    expect(json_encode(Arr::except($off, 'now')))->toBe(json_encode(Arr::except($plain, 'now')))
        ->and($plain['result']['slowest_by_total_time'])->toEqual([['type' => 'request', 'group' => md5('/orders'), 'label' => '/orders', 'occurrences' => 2, 'total_ms' => 3000.0]])
        ->and([...$on['result'], 'slowest_by_total_time' => null])->toEqual([...$plain['result'], 'slowest_by_total_time' => null]);
});

it('refuses a by_deploy that is not true or false', function (mixed $value, string $shown) {
    $response = FirewatchServer::tool(Overview::class, ['by_deploy' => $value]);

    $response->assertHasErrors([__('firewatch::messages.invalid_argument', ['argument' => 'by_deploy', 'expected' => 'true or false', 'value' => $shown, 'accepted' => 'true or false', 'example' => 'overview(by_deploy: true)'])]);
})->with([
    'a string' => ['yes', '"yes"'],
    'a number' => [1, '1'],
]);

it('offers the comparison of the two most recent deploys on the type of the slowest group, and it runs', function () {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 10, 'v1'),
        byDeployTimed(RecordType::REQUEST, '/orders', 5_000_000, 20, 'v2'),
        byDeployTimed(RecordType::REQUEST, '/orders', 2_000_000, 30, 'v3'),
    ]);

    $answer = Answers::assert(Overview::class, ['by_deploy' => true, 'since' => 1790690000]);

    expect($answer['next'][0])->toBe([
        'tool' => 'compare',
        'arguments' => ['type' => 'request', 'deploy_before' => 'v2', 'deploy_after' => 'v3', 'since' => '1790690000'],
        'why' => __('firewatch::messages.overview_next_compare', ['type' => 'request']),
    ])->and(Answers::follow($answer['next'][0])['tool'])->toBe('compare');
});

it('offers no comparison when fewer than two deploys are shown by name', function (?string $other) {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 10, 'v1'),
        ...($other === null ? [] : [byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 20, $other === '' ? null : $other)]),
    ]);

    expect(array_column(Answers::assert(Overview::class, ['by_deploy' => true])['next'], 'tool'))->not->toContain('compare');
})->with([
    'one deploy' => [null],
    'one deploy and none' => [''],
]);

it('does not offer the comparison without the argument', function () {
    ingest([
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 10, 'v1'),
        byDeployTimed(RecordType::REQUEST, '/orders', 1_000_000, 20, 'v2'),
    ]);

    expect(array_column(Answers::assert(Overview::class)['next'], 'tool'))->not->toContain('compare');
});

it('shows no blocks when no group of the window has a duration', function () {
    ingest([syntheticRecord(RecordType::LOG)]);

    expect(Answers::assert(Overview::class, ['by_deploy' => true])['result']['slowest_by_total_time'])->toBe([]);
});
