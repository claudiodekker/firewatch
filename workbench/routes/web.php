<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\ShipOrder;
use Workbench\App\Jobs\SyncInventory;

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
