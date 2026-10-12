<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * @param  array<string, mixed>  $rules
 */
function serveSignup(array $rules = ['email' => ['required', 'email'], 'password' => ['required', 'min:8']]): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::post('/signup', function (Request $request) use ($rules) {
        $request->validate($rules);

        return redirect('/welcome');
    });
}

/**
 * @return list<array{status_code: int, invalid_fields: mixed}>
 */
function storedInvalidFields(): array
{
    return array_map(function (array $row) {
        $row['invalid_fields'] = $row['invalid_fields'] === null ? null : json_decode($row['invalid_fields'], associative: true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }, storeRows('SELECT status_code, invalid_fields FROM requests ORDER BY id'));
}

it('stores the names of the fields that failed validation, for a form that is redirected back and for a JSON answer', function () {
    serveSignup();

    test()->post('/signup', ['email' => 'not-an-address', 'password' => 'short']);
    test()->postJson('/signup', ['email' => 'taylor@example.com', 'password' => 'short']);

    expect(storedInvalidFields())->toBe([
        ['status_code' => 302, 'invalid_fields' => ['email', 'password']],
        ['status_code' => 422, 'invalid_fields' => ['password']],
    ]);
});

it('stores no invalid fields for a request that passed validation, also right after one that failed', function () {
    serveSignup();

    test()->post('/signup', ['email' => 'not-an-address', 'password' => 'short']);
    test()->post('/signup', ['email' => 'taylor@example.com', 'password' => 'long-enough']);

    expect(storedInvalidFields())->toBe([
        ['status_code' => 302, 'invalid_fields' => ['email', 'password']],
        ['status_code' => 302, 'invalid_fields' => null],
    ]);
});

it('stores no invalid fields for a request that failed another way with the same status', function () {
    forceRequests();

    Route::post('/signup', fn () => abort(422));

    test()->postJson('/signup');

    expect(storedInvalidFields())->toBe([['status_code' => 422, 'invalid_fields' => null]]);
});

it('stores neither the values that were sent nor the messages that name them', function () {
    serveSignup(['email' => ['required', 'email']]);

    Route::post('/coupons', fn (Request $request) => throw ValidationException::withMessages([
        'code' => "The coupon {$request->input('code')} has expired.",
    ]));

    test()->post('/signup', ['email' => 'not-an-address']);
    test()->post('/coupons', ['code' => 'SPRING-SECRET']);

    $stored = implode('', array_column(storeRows('SELECT data FROM requests'), 'data'));

    expect($stored)->not->toContain('not-an-address')
        ->and($stored)->not->toContain('SPRING-SECRET')
        ->and($stored)->not->toContain('expired')
        ->and(array_column(storedInvalidFields(), 'invalid_fields'))->toBe([['email'], ['code']]);
});

it('stores an empty list for a validation failure that names no field', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::post('/signup', fn () => throw ValidationException::withMessages([]));

    test()->post('/signup');

    expect(storedInvalidFields())->toBe([['status_code' => 302, 'invalid_fields' => []]]);
});

it('keeps up to 100 field names and replaces the rest with their count', function (int $fields, array $stored) {
    serveSignup(['items.*.sku' => ['required']]);

    test()->post('/signup', ['items' => array_fill(0, $fields, ['sku' => ''])]);

    expect(storedInvalidFields()[0]['invalid_fields'])->toBe($stored);
})->with([
    'exactly the limit' => ['fields' => 100, 'stored' => array_map(fn (int $item) => "items.{$item}.sku", range(0, 99))],
    'one over' => ['fields' => 101, 'stored' => [...array_map(fn (int $item) => "items.{$item}.sku", range(0, 98)), '... [2 more fields]']],
]);

it('cuts a field name longer than 255 bytes with the truncation marker', function () {
    $name = str_repeat('a', 300);

    serveSignup([$name => ['required']]);

    test()->post('/signup');

    $marker = '... [truncated, 300 bytes total]';

    expect(storedInvalidFields()[0]['invalid_fields'])->toBe([str_repeat('a', 255 - strlen($marker)).$marker]);
});

it('does not give a request the fields of a request that was handled while it terminated', function () {
    serveSignup();

    Route::get('/welcome', function () {
        app()->terminating(fn () => app(Kernel::class)->handle(Request::create('/signup', 'POST', ['email' => 'not-an-address'])));

        return 'ok';
    });

    test()->get('/welcome');

    expect(storedInvalidFields())->toBe([['status_code' => 200, 'invalid_fields' => null]]);
});

it('stores no invalid fields for a validator whose errors can\'t be read, and still records the request', function () {
    forceRequests();
    Exceptions::fake();

    $validator = new class(app('translator'), [], ['email' => ['required']]) extends Validator
    {
        protected bool $read = false;

        public function errors()
        {
            if ($this->read) {
                throw new RuntimeException('The errors can\'t be read.');
            }

            $this->read = true;

            return parent::errors();
        }
    };

    Route::post('/signup', fn () => response('invalid', 422)->withException(new ValidationException($validator)));

    test()->post('/signup');

    expect(storedInvalidFields())->toBe([['status_code' => 422, 'invalid_fields' => null]])
        ->and(notices())->toBe(['Firewatch could not read the fields a request failed validation on: The errors can\'t be read.'])
        ->and(storeRows('SELECT message FROM exceptions'))->toBe([]);
    Exceptions::assertNothingReported();
});
