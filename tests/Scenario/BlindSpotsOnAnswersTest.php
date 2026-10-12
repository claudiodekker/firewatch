<?php

use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\Route;

/**
 * Serve an order list that works and a report that throws, in a fresh application.
 */
function blindSpotRequests(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/orders', fn () => 'ok');
    Route::get('/reports', fn () => abort(500));

    foreach (['/orders', '/orders', '/reports'] as $uri) {
        test()->get($uri);
    }
}

/**
 * Get the structural blind spots of an answer by id, each with whether it prints its sentence.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, bool>
 */
function structuralPrinting(array $answer): array
{
    $structural = array_filter($answer['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'structural');

    return array_combine(array_column($structural, 'id'), array_map(fn (array $blindSpot) => isset($blindSpot['message']), $structural));
}

it('names a structural blind spot by id alone beside a result that lists what was found', function () {
    blindSpotRequests();

    $answer = Answers::assert(Rank::class, ['type' => 'request', 'by' => 'occurrences']);

    expect($answer['result']['groups'])->not->toBe([])
        ->and(structuralPrinting($answer))->toBe(array_fill_keys(array_column(BlindSpots::for([RecordType::REQUEST]), 'id'), false))
        ->and(array_keys($answer))->toBe(Answers::KEYS);
});

it('keeps the conditions of the store in full beside a result that lists what was found', function () {
    blindSpotRequests();
    test()->artisan('firewatch:clear', ['--type' => 'query', '--force' => true]);

    $conditions = array_filter(Answers::assert(Rank::class, ['type' => 'request', 'by' => 'occurrences'])['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'condition');

    expect($conditions)->each->toHaveKey('message');
});

it('prints every sentence on an answer that found nothing', function () {
    blindSpotRequests();

    $answer = Answers::assert(Occurrences::class, ['type' => 'mail']);

    expect($answer['empty'])->not->toBeNull()
        ->and(array_values(array_unique(structuralPrinting($answer))))->toBe([true]);
});

it('prints every sentence on a detector that came back clean, and by id on one with findings', function () {
    blindSpotRequests();

    $found = Answers::assert(Detect::class, ['shape' => 'failing-routes']);
    $clean = Answers::assert(Detect::class, ['shape' => 'failing-http']);

    expect($found['result']['verdict'])->toBe('findings')
        ->and(array_values(array_unique(structuralPrinting($found))))->toBe([false])
        ->and($clean['result']['verdict'])->toBe('not_evaluated')
        ->and(array_values(array_unique(structuralPrinting($clean))))->toBe([true]);
});

it('prints every sentence on an overview whose detectors found nothing, with the shapes still listed', function () {
    blindSpotRequests();

    $answer = Answers::assert(Overview::class);

    expect(array_column($answer['result']['detectors'], 'verdict'))->toContain('clean')
        ->and(array_values(array_unique(structuralPrinting($answer))))->toBe([true]);
});

it('holds every sentence in describe, so a blind spot named by id is never lost', function () {
    blindSpotRequests();

    $rank = Answers::assert(Rank::class, ['type' => 'request', 'by' => 'occurrences']);
    $described = Answers::assert(Describe::class)['result']['blind_spots'];

    expect($described)->toBe(array_map(fn (string $id, string $sentence) => ['id' => $id, 'sentence' => $sentence], array_keys(BlindSpots::catalogue()), BlindSpots::catalogue()))
        ->and(array_diff(array_keys(structuralPrinting($rank)), array_column($described, 'id')))->toBe([]);
});

it('prints the sentences of a type when describe is asked about it', function () {
    blindSpotRequests();

    expect(array_values(array_unique(structuralPrinting(Answers::assert(Describe::class, ['type' => 'request'])))))->toBe([true]);
});
