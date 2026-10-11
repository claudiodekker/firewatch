<?php

use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\ErrorCode;
use ClaudioDekker\Firewatch\Mcp\RecipeArguments;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\TestCase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

uses(TestCase::class);

function recipeArguments(RecordType $type, array $given): RecipeArguments
{
    $arguments = new Arguments(new Request(['type' => $type->value, ...$given]), new class extends Tool {});

    return RecipeArguments::read($arguments, $type);
}

it('reads the source arguments of each type into the fields its recipe reads', function (RecordType $type, array $given, array $fields, ?string $driver) {
    config(['app.timezone' => 'UTC', 'app.schedule_timezone' => 'Europe/Amsterdam']);

    $read = recipeArguments($type, $given);

    expect($read->fields)->toBe($fields)
        ->and($read->driver)->toBe($driver);
})->with([
    'request: sorted methods, HEAD beside GET, trimmed path' => [RecordType::REQUEST, ['methods' => ['POST', 'GET'], 'path' => 'users/{user}/'], ['route_domain' => '', 'route_methods' => ['GET', 'HEAD', 'POST'], 'route_path' => '/users/{user}'], null],
    'request: scheme stripped from the domain' => [RecordType::REQUEST, ['methods' => ['PUT'], 'path' => '/a', 'domain' => 'https://{tenant}.example.com'], ['route_domain' => '{tenant}.example.com', 'route_methods' => ['PUT'], 'route_path' => '/a'], null],
    'command' => [RecordType::COMMAND, ['name' => 'app:sync'], ['name' => 'app:sync'], null],
    'job attempt' => [RecordType::JOB_ATTEMPT, ['name' => 'App\\Jobs\\Ship'], ['name' => 'App\\Jobs\\Ship'], null],
    'queued job' => [RecordType::QUEUED_JOB, ['name' => 'App\\Jobs\\Ship'], ['name' => 'App\\Jobs\\Ship'], null],
    'scheduled task: the schedule timezone and no repeat by default' => [RecordType::SCHEDULED_TASK, ['name' => 'inspire', 'cron' => '* * * * *'], ['timezone' => 'Europe/Amsterdam', 'repeat_seconds' => 0, 'name' => 'inspire', 'cron' => '* * * * *'], null],
    'scheduled task: named timezone and repeat' => [RecordType::SCHEDULED_TASK, ['name' => 'inspire', 'cron' => '* * * * *', 'timezone' => 'UTC', 'repeat_seconds' => 15], ['timezone' => 'UTC', 'repeat_seconds' => 15, 'name' => 'inspire', 'cron' => '* * * * *'], null],
    'query: driver lowercased and kept apart from the fields' => [RecordType::QUERY, ['connection' => 'main', 'sql' => 'select 1', 'driver' => 'MySQL'], ['connection' => 'main', 'sql' => 'select 1'], 'mysql'],
    'cache event' => [RecordType::CACHE_EVENT, ['store' => 'redis', 'key' => 'k'], ['store' => 'redis', 'key' => 'k'], null],
    'outgoing request' => [RecordType::OUTGOING_REQUEST, ['host' => 'example.com'], ['host' => 'example.com'], null],
    'mail' => [RecordType::MAIL, ['class' => 'App\\Mail\\Welcome'], ['class' => 'App\\Mail\\Welcome'], null],
    'notification' => [RecordType::NOTIFICATION, ['class' => 'App\\Notifications\\Paid'], ['class' => 'App\\Notifications\\Paid'], null],
]);

it('gives a task no timezone when the schedule timezone is set to null', function () {
    config(['app.timezone' => 'UTC', 'app.schedule_timezone' => null]);

    expect(RecipeArguments::scheduleTimezone())->toBe('');
});

it('refuses an argument that is missing, foreign or malformed', function (RecordType $type, array $given, ErrorCode $code, string $argument) {
    try {
        recipeArguments($type, $given);
    } catch (Refusal $refusal) {
        expect($refusal->error)->toBe($code)
            ->and($refusal->getMessage())->toContain($argument);

        return;
    }

    $this->fail('The arguments were accepted.');
})->with([
    'missing required' => [RecordType::COMMAND, [], ErrorCode::MISSING_ARGUMENT, 'name'],
    'foreign argument' => [RecordType::COMMAND, ['name' => 'a', 'host' => 'b'], ErrorCode::CONFLICTING_ARGUMENTS, 'host'],
    'lowercase method' => [RecordType::REQUEST, ['methods' => ['get'], 'path' => '/a'], ErrorCode::INVALID_ARGUMENT, 'methods'],
    'empty methods' => [RecordType::REQUEST, ['methods' => [], 'path' => '/a'], ErrorCode::INVALID_ARGUMENT, 'methods'],
    'non-string path' => [RecordType::REQUEST, ['methods' => ['GET'], 'path' => 5], ErrorCode::INVALID_ARGUMENT, 'path'],
    'repeat that does not divide the minute' => [RecordType::SCHEDULED_TASK, ['name' => 'a', 'cron' => '* * * * *', 'repeat_seconds' => 7], ErrorCode::INVALID_ARGUMENT, 'repeat_seconds'],
    'empty driver' => [RecordType::QUERY, ['connection' => 'c', 'sql' => 's', 'driver' => ''], ErrorCode::INVALID_ARGUMENT, 'driver'],
]);
