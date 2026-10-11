<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Date;

const UNAVAILABLE_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(UNAVAILABLE_AT));
});

/**
 * Write a file that is not a Firewatch store where the store belongs.
 */
function unavailableForeignFile(string $path): void
{
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));
}

it('answers a store that is missing or is not Firewatch\'s, stating the coverage of what the call would read', function (string $tool, array $arguments, array $typesRead, ?Closure $arrange, string $kind, string $key, string $state, ?string $reason) {
    $path = app(Configuration::class)->database;

    if ($arrange !== null) {
        $arrange($path);
    }

    $envelope = Envelope::assert($tool, $arguments);

    expect($envelope['empty'])->toBe(['kind' => $kind, 'population' => null, 'message' => __("firewatch::messages.{$key}", ['path' => $path])])
        ->and($envelope['coverage'])->toMatchArray(['state' => $state, 'reason' => $reason, 'oldest_at' => null, 'newest_at' => null, 'records' => null, 'types_read' => $typesRead, 'straddling' => null])
        ->and($envelope['coverage']['history'])->toMatchArray(['from' => null, 'reason' => null])
        ->and($envelope['next'])->toBe([]);

    if ($arrange === null) {
        expect(dirname($path))->not->toBeDirectory();
    }
})->with([
    'actor' => [Actor::class, ['who' => 'taylor'], array_column(RecordType::events(), 'value')],
    'compare' => [Compare::class, ['type' => 'request', 'split_at' => UNAVAILABLE_AT - 600], ['request']],
    'describe' => [Describe::class, [], array_column(RecordType::events(), 'value')],
    'detect' => [Detect::class, ['shape' => 'failing-routes'], ['request']],
    'execution' => [Execution::class, [], ['request', 'command', 'job-attempt', 'scheduled-task']],
    'fingerprint' => [Fingerprint::class, ['type' => 'command', 'name' => 'env'], ['command']],
    'occurrences' => [Occurrences::class, ['type' => 'request'], ['request']],
    'overview' => [Overview::class, [], array_column(RecordType::events(), 'value')],
    'query' => [Query::class, ['sql' => 'SELECT 1'], []],
    'rank' => [Rank::class, ['type' => 'request'], ['request']],
    'trace' => [Trace::class, ['trace_id' => 'abc'], ['request', 'command', 'job-attempt', 'scheduled-task', 'queued-job']],
    'trend' => [Trend::class, ['type' => 'request'], ['request']],
])->with([
    'no store' => [null, 'no_store', 'no_store', 'absent', null],
    'a foreign file' => [unavailableForeignFile(...), 'store_unusable', 'store_unusable.foreign_file', 'unusable', 'foreign_file'],
]);
