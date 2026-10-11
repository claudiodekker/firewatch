<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ItemNotFoundException;
use Laravel\Nightwatch\Core;

/**
 * Serve requests in a fresh application whose exception sensor is rooted at this checkout, so its tests are application code and its vendor/ is the vendor Nightwatch skips, with the fixture views.
 */
function throwSiteApplication(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    app(Core::class)->sensor->location->setBasePath(dirname(__DIR__, 2));
    View::addLocation(dirname(__DIR__).'/Fixtures/views');
}

/**
 * Get the file a test throws from, as Nightwatch names it.
 */
function throwSiteFile(): string
{
    return 'tests/Scenario/DidThisThrowEverFireTest.php';
}

it('finds the group of an exception thrown on an application line, and the recipe check agrees', function () {
    throwSiteApplication();

    Route::get('/invoices', fn () => throw new DomainException('The invoice is not renderable.'));
    $line = __LINE__ - 1;

    test()->get('/invoices');

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => DomainException::class, 'file' => throwSiteFile(), 'line' => $line]);
    $input = DomainException::class.',0,'.throwSiteFile().",{$line}";

    expect($envelope['result']['candidates'])->toBe([['group' => hash('xxh128', $input), 'input' => $input, 'assumption' => __('firewatch::messages.fingerprint_assumption_code_zero')]])
        ->and($envelope['result']['held'])->toHaveCount(1)
        ->and($envelope['result']['held'][0])->toMatchArray(['group' => hash('xxh128', $input), 'type' => 'exception', 'records' => 1, 'label' => DomainException::class])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'exception', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['empty'])->toBeNull()
        ->and($envelope['notes'])->toBe([]);
});

it('finds an exception thrown inside the framework by the application line that called it, not the vendor line', function () {
    throwSiteApplication();

    Route::get('/drafts', fn () => collect()->firstOrFail());
    $line = __LINE__ - 1;

    test()->get('/drafts');

    [$thrown] = storeRows('SELECT trace FROM exceptions');
    $vendor = json_decode($thrown['trace'], true)[0]['file'];
    [$vendorFile, $vendorLine] = explode(':', $vendor);

    $caller = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => ItemNotFoundException::class, 'file' => throwSiteFile(), 'line' => $line]);
    $inside = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => ItemNotFoundException::class, 'file' => $vendorFile, 'line' => (int) $vendorLine]);

    expect($vendorFile)->toStartWith('vendor/laravel/framework/')
        ->and($caller['result']['held'])->toHaveCount(1)
        ->and($caller['result']['recipe_check'][0]['check'])->toBe('agrees')
        ->and($inside['result']['held'])->toBe([])
        ->and($inside['empty']['kind'])->toBe('no_match')
        ->and($inside['notes'])->toBe([__('firewatch::messages.fingerprint_vendor_note')]);
});

it('finds an exception thrown in a Blade template by the template and no line, and states the view rule', function () {
    throwSiteApplication();

    Route::get('/previews', fn () => view('invoice'));

    test()->get('/previews');

    $file = 'tests/Fixtures/views/invoice.blade.php';
    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => RuntimeException::class, 'file' => $file]);
    $lined = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => RuntimeException::class, 'file' => $file, 'line' => 2]);
    $input = RuntimeException::class.",0,{$file},";
    $zero = __('firewatch::messages.fingerprint_assumption_code_zero');

    expect($envelope['result']['candidates'])->toBe([['group' => hash('xxh128', $input), 'input' => $input, 'assumption' => $zero]])
        ->and(array_column($envelope['result']['held'], 'group'))->toBe([hash('xxh128', $input)])
        ->and($envelope['result']['recipe_check'][0]['check'])->toBe('agrees')
        ->and($envelope['notes'])->toBe([__('firewatch::messages.fingerprint_view_note')])
        ->and(array_column($lined['result']['candidates'], 'input'))->toBe([$input, RuntimeException::class.",0,{$file},2"])
        ->and(array_column($lined['result']['candidates'], 'assumption'))->toBe([__('firewatch::messages.fingerprint_assumption_view')."; {$zero}", __('firewatch::messages.fingerprint_assumption_ignition')."; {$zero}"])
        ->and(array_column($lined['result']['held'], 'group'))->toBe([hash('xxh128', $input)]);
});

it('fingerprints a database exception by the SQLSTATE it carries as its code', function () {
    throwSiteApplication();

    Schema::create('invoices', fn (Blueprint $table) => $table->string('number')->unique());
    Route::get('/issues', fn () => DB::table('invoices')->insert([['number' => 'F-1'], ['number' => 'F-1']]));
    $line = __LINE__ - 1;

    test()->get('/issues');

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => UniqueConstraintViolationException::class, 'file' => throwSiteFile(), 'line' => $line]);
    $site = throwSiteFile().",{$line}";
    $zero = UniqueConstraintViolationException::class.",0,{$site}";
    $sqlState = UniqueConstraintViolationException::class.",23000,{$site}";

    expect(storeRows('SELECT code FROM exceptions'))->toBe([['code' => '23000']])
        ->and($envelope['result']['candidates'])->toBe([
            ['group' => hash('xxh128', $zero), 'input' => $zero, 'assumption' => __('firewatch::messages.fingerprint_assumption_code_zero')],
            ['group' => hash('xxh128', $sqlState), 'input' => $sqlState, 'assumption' => __('firewatch::messages.fingerprint_assumption_code_held', ['code' => '23000'])],
        ])
        ->and(array_column($envelope['result']['held'], 'group'))->toBe([hash('xxh128', $sqlState)])
        ->and($envelope['result']['recipe_check'][0]['check'])->toBe('agrees');
});

it('says the store does not hold a throw that never ran, and that the recipe check lets the assistant trust the miss', function () {
    throwSiteApplication();

    Route::get('/invoices', fn () => throw new DomainException('The invoice is not renderable.'));
    Route::get('/refunds', fn () => throw new DomainException('The refund is not allowed.'));
    $line = __LINE__ - 1;

    test()->get('/invoices');

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => DomainException::class, 'file' => throwSiteFile(), 'line' => $line]);

    expect($envelope['result']['held'])->toBe([])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'exception', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.agrees', ['group' => $envelope['result']['candidates'][0]['group'], 'types' => 'exception']))
        ->and($envelope['next'])->toBe([]);
});

it('finds an exception thrown in a template included by another, whose render Nightwatch appends to the file', function () {
    throwSiteApplication();

    Route::get('/statements', fn () => view('statement'));

    test()->get('/statements');

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'exception', 'class' => RuntimeException::class, 'file' => 'tests/Fixtures/views/invoice.blade.php']);
    [$stored] = storeRows('SELECT file FROM exceptions');
    $zero = __('firewatch::messages.fingerprint_assumption_code_zero');

    expect($stored['file'])->toStartWith('tests/Fixtures/views/invoice.blade.php) (View: ')
        ->and(array_column($envelope['result']['candidates'], 'assumption'))->toBe([$zero, __('firewatch::messages.fingerprint_assumption_nested')."; {$zero}"])
        ->and(array_column($envelope['result']['held'], 'group'))->toBe([$envelope['result']['candidates'][1]['group']])
        ->and($envelope['result']['recipe_check'][0]['check'])->toBe('agrees');
});
