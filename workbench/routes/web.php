<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\ShipOrder;
use Workbench\App\Jobs\SyncInventory;
use Workbench\App\Members;

Route::get('/', fn () => 'ok');

Route::get('/products', function () {
    foreach (range(1, 25) as $product) {
        DB::select('select ? as product', [$product]);
    }

    return 'ok';
});

Route::get('/purchases', function () {
    ShipOrder::dispatch();
    ChargeCard::dispatch();
    SyncInventory::dispatch();

    return 'ok';
});

Route::get('/invoices/{invoice}', fn (string $invoice) => throw new RuntimeException("Invoice [{$invoice}] could not be rendered."));

Route::get('/exports', fn () => strlen(str_repeat('a', 80 * 1024 * 1024)));

Route::get('/quotes', function () {
    Http::fake(['https://rates.example.com/*' => Http::response('unavailable', 503)]);

    Http::get('https://rates.example.com/quotes?currency=EUR');

    return 'ok';
});

Route::get('/catalog', fn () => Cache::remember('catalog', 60, fn () => 'spring'));

Route::get('/members/{member}', function (string $member) {
    Auth::setUser(Members::find($member) ?? abort(404));

    return 'ok';
});

Route::get('/members/{member}/orders', function (string $member) {
    $signedIn = Members::find($member) ?? abort(404);

    // Dispatched before the member signs in, so the job's Context carries no user and its attempt reaches the member only through the dispatch.
    ShipOrder::dispatch();

    Auth::setUser($signedIn);

    return 'ok';
});
