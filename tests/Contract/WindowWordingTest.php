<?php

use ClaudioDekker\Firewatch\Mcp\Scope;
use ClaudioDekker\Firewatch\Mcp\Window;

test('the window line names the scope and then the test the window is limited to', function (Scope $scope, ?string $test, string $line) {
    $window = Window::between(since: null, until: null, timezone: 'UTC')->scoped($scope)->limitedTo($test);

    expect($window->line())->toBe($line);
})->with([
    'neither' => [Scope::ALL, null, 'Window: none (unbounded)'],
    'a scope' => [Scope::BETWEEN, null, 'Window: none (unbounded); scope: between'],
    'a test' => [Scope::ALL, 'Tests\Feature\OrdersTest::it lists orders', 'Window: none (unbounded); test: Tests\Feature\OrdersTest::it lists orders'],
    'a scope and a test' => [Scope::EXECUTIONS, 'Tests\Feature\OrdersTest::it lists orders', 'Window: none (unbounded); scope: executions; test: Tests\Feature\OrdersTest::it lists orders'],
]);
