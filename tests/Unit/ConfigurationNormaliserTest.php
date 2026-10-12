<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;

function configurationNormaliser(): ConfigurationNormaliser
{
    return new ConfigurationNormaliser(basePath: '/app', publicPath: '/app/public', storagePath: '/app/storage', environment: 'testing');
}

/**
 * @return list<string>
 */
function configurationIssueLines(Configuration $configuration): array
{
    return array_map(fn (ConfigurationIssue $issue) => $issue->line(), $configuration->issues);
}

it('reads every accepted boolean spelling', function (mixed $value, bool $expected) {
    $configuration = configurationNormaliser()->resolve(['enabled' => $value]);

    expect($configuration->enabled)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'typed true' => [true, true],
    'typed false' => [false, false],
    'true' => ['true', true],
    'false' => ['false', false],
    '1' => ['1', true],
    '0' => ['0', false],
    'yes' => ['yes', true],
    'no' => ['no', false],
    'on' => ['on', true],
    'off' => ['off', false],
    'mixed case with whitespace' => [' TRUE ', true],
    'upper case off' => ['OFF', false],
    'integer 1' => [1, true],
    'integer 0' => [0, false],
]);

it('falls back to the default for a refused boolean', function (mixed $value, string $line) {
    $configuration = configurationNormaliser()->resolve(['enabled' => $value]);

    expect($configuration->enabled)->toBeTrue()
        ->and(configurationIssueLines($configuration))->toBe([$line]);
})->with([
    'word' => ['maybe', 'firewatch.enabled: "maybe" is not a boolean (true, false, 1, 0, yes, no, on, off); using true'],
    'integer 2' => [2, 'firewatch.enabled: 2 is not a boolean (true, false, 1, 0, yes, no, on, off); using true'],
    'float' => [1.0, 'firewatch.enabled: 1.0 is not a boolean (true, false, 1, 0, yes, no, on, off); using true'],
    'null' => [null, 'firewatch.enabled: null is not a boolean (true, false, 1, 0, yes, no, on, off); using true'],
]);

it('falls back to each boolean key\'s own default alone', function (string $group, string $key, string $property) {
    $configuration = configurationNormaliser()->resolve([$group => [$key => 'maybe'], 'enabled' => false]);

    expect($configuration->{$property})->toBeTrue()
        ->and($configuration->enabled)->toBeFalse()
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.{$group}.{$key}: \"maybe\" is not a boolean (true, false, 1, 0, yes, no, on, off); using true"]);
})->with([
    'capture.logs' => ['group' => 'capture', 'key' => 'logs', 'property' => 'captureLogs'],
    'capture.request_payload' => ['group' => 'capture', 'key' => 'request_payload', 'property' => 'captureRequestPayload'],
]);

it('reads the busy timeout within its range', function (mixed $value, int $expected) {
    $configuration = configurationNormaliser()->resolve(['busy_timeout' => $value]);

    expect($configuration->busyTimeoutMilliseconds)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'lower bound' => [0, 0],
    'upper bound' => [5000, 5000],
    'digit string' => ['300', 300],
    'digit string with whitespace' => [' 450 ', 450],
    'leading zeros' => ['0300', 300],
    'only zeros' => ['00', 0],
]);

it('falls back to 300 for a refused busy timeout', function (mixed $value, string $described) {
    $configuration = configurationNormaliser()->resolve(['busy_timeout' => $value]);

    expect($configuration->busyTimeoutMilliseconds)->toBe(300)
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.busy_timeout: {$described} is not an integer from 0 to 5000; using 300"]);
})->with([
    'above the range' => [5001, '5001'],
    'negative' => [-1, '-1'],
    'negative string' => ['-1', '"-1"'],
    'signed string' => ['+5', '"+5"'],
    'fraction' => [1.5, '1.5'],
    'fraction string' => ['1.5', '"1.5"'],
    'exponent' => ['1e3', '"1e3"'],
    'overflowing digits' => ['99999999999999999999', '"99999999999999999999"'],
    'boolean' => [true, 'true'],
]);

it('reads the record retention within its range', function (mixed $value, int $expected) {
    $configuration = configurationNormaliser()->resolve(['retention' => ['records' => $value]]);

    expect($configuration->retentionRecords)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'lower bound' => [1, 1],
    'upper bound' => [10000000, 10000000],
    'digit string' => ['250000', 250000],
]);

it('falls back to 100000 for a refused record retention', function (mixed $value, string $described) {
    $configuration = configurationNormaliser()->resolve(['retention' => ['records' => $value]]);

    expect($configuration->retentionRecords)->toBe(100000)
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.retention.records: {$described} is not an integer from 1 to 10000000; using 100000"]);
})->with([
    'zero' => [0, '0'],
    'above the range' => [10000001, '10000001'],
]);

it('reads a duration in each unit', function (string $value, int $seconds) {
    $configuration = configurationNormaliser()->resolve(['retention' => ['age' => $value]]);

    expect($configuration->retentionAge)->toBe($value)
        ->and($configuration->retentionAgeSeconds)->toBe($seconds)
        ->and($configuration->issues)->toBe([]);
})->with([
    'seconds' => ['1s', 1],
    'minutes' => ['90m', 5400],
    'hours' => ['2h', 7200],
    'days' => ['7d', 604800],
    'six digits of weeks' => ['999999w', 604799395200],
]);

it('falls back to 7d for a refused duration', function (mixed $value, string $described) {
    $configuration = configurationNormaliser()->resolve(['retention' => ['age' => $value]]);

    expect($configuration->retentionAge)->toBe('7d')
        ->and($configuration->retentionAgeSeconds)->toBe(604800)
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.retention.age: {$described} is not a duration (digits then s, m, h, d or w, for example 7d); using 7d"]);
})->with([
    'seven digits' => ['1000000d', '"1000000d"'],
    'zero' => ['0d', '"0d"'],
    'leading zero' => ['07d', '"07d"'],
    'bare number' => ['7', '"7"'],
    'integer' => [7, '7'],
    'compound' => ['1d12h', '"1d12h"'],
    'space before the unit' => ['7 d', '"7 d"'],
    'words' => ['7 days', '"7 days"'],
]);

it('falls back for one key without touching the others', function () {
    $configuration = configurationNormaliser()->resolve(['busy_timeout' => 'soon', 'retention' => ['age' => '1d', 'records' => 'many']]);

    expect($configuration)->toHaveProperties(['busyTimeoutMilliseconds' => 300, 'retentionAge' => '1d', 'retentionRecords' => 100000])
        ->and(configurationIssueLines($configuration))->toBe([
            'firewatch.busy_timeout: "soon" is not an integer from 0 to 5000; using 300',
            'firewatch.retention.records: "many" is not an integer from 1 to 10000000; using 100000',
        ]);
});

it('reads a list from a comma-separated string or an array', function (mixed $value, array $expected) {
    $configuration = configurationNormaliser()->resolve(['capture' => ['redact_headers' => $value]]);

    expect($configuration->redactHeaders)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'comma-separated string' => ['X-Api-Key, Cookie', ['X-Api-Key', 'Cookie']],
    'items trimmed and empties dropped' => [' a ,, b ,', ['a', 'b']],
    'duplicates removed keeping the first, case preserved' => ['a,A,a,b', ['a', 'A', 'b']],
    'array' => [[' token ', '', 'Token', 'token'], ['token', 'Token']],
    'empty string means none' => ['', []],
    'empty array means none' => [[], []],
]);

it('drops a list item that is not a string', function (string $name, string $property, string $key) {
    $configuration = configurationNormaliser()->resolve(['capture' => [$name => ['password', 5, ['nested']]]]);

    expect($configuration->{$property})->toBe(['password'])
        ->and(configurationIssueLines($configuration))->toBe([
            "firewatch.{$key}: 5 is not a string; dropped",
            "firewatch.{$key}: array is not a string; dropped",
        ]);
})->with([
    'capture.redact_payload_fields' => ['name' => 'redact_payload_fields', 'property' => 'redactPayloadFields', 'key' => 'capture.redact_payload_fields'],
    'capture.redact_headers' => ['name' => 'redact_headers', 'property' => 'redactHeaders', 'key' => 'capture.redact_headers'],
]);

it('falls back to none when a redact list is neither an array nor a string', function () {
    $configuration = configurationNormaliser()->resolve(['capture' => ['redact_payload_fields' => 5]]);

    expect($configuration->redactPayloadFields)->toBe([])
        ->and(configurationIssueLines($configuration))->toBe(['firewatch.capture.redact_payload_fields: 5 is not a list (an array or a comma-separated string); using none']);
});

it('keeps valid environment names', function (mixed $value, array $expected) {
    $configuration = configurationNormaliser()->resolve(['environments' => $value]);

    expect($configuration->environments)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'comma-separated string' => ['local, staging', ['local', 'staging']],
    'letters, digits and _ . -' => [['dev_2', 'qa.eu-west'], ['dev_2', 'qa.eu-west']],
    'production' => ['production', ['production']],
]);

it('drops an invalid environment name and keeps the rest', function (mixed $item, string $described) {
    $configuration = configurationNormaliser()->resolve(['environments' => ['local', $item]]);

    expect($configuration->environments)->toBe(['local'])
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.environments: {$described} is not an environment name (letters, digits, _ . -); dropped"]);
})->with([
    'wildcard' => ['prod*', '"prod*"'],
    'bare wildcard' => ['*', '"*"'],
    'space' => ['my env', '"my env"'],
    'not a string' => [5, '5'],
]);

it('uses local when no environment name remains', function (mixed $value, array $lines) {
    $configuration = configurationNormaliser()->resolve(['environments' => $value]);

    expect($configuration->environments)->toBe(['local'])
        ->and(configurationIssueLines($configuration))->toBe($lines);
})->with([
    'empty list' => [[], ['firewatch.environments: no valid environment names; using local']],
    'empty string' => ['', ['firewatch.environments: no valid environment names; using local']],
    'every item invalid' => ['prod*', [
        'firewatch.environments: "prod*" is not an environment name (letters, digits, _ . -); dropped',
        'firewatch.environments: no valid environment names; using local',
    ]],
    'not a list' => [true, ['firewatch.environments: true is not a list (an array or a comma-separated string); using local']],
]);

it('resolves the store path', function (string $value, string $expected) {
    $configuration = configurationNormaliser()->resolve(['database' => $value]);

    expect($configuration->database)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'relative, against the base path' => ['database/firewatch.sqlite', '/app/database/firewatch.sqlite'],
    'absolute' => ['/var/firewatch/store.sqlite', '/var/firewatch/store.sqlite'],
    'windows absolute' => ['C:\\firewatch\\store.sqlite', 'C:\\firewatch\\store.sqlite'],
    'beside the public directory' => ['publicity/store.sqlite', '/app/publicity/store.sqlite'],
    'leaving the public directory again' => ['public/../storage/store.sqlite', '/app/public/../storage/store.sqlite'],
    '4096 bytes' => ['/'.str_repeat('a', 4095), '/'.str_repeat('a', 4095)],
]);

it('falls back to the default store path for a refused path', function (mixed $value, string $reason) {
    $configuration = configurationNormaliser()->resolve(['database' => $value]);

    expect($configuration->database)->toBe('/app/storage/firewatch/firewatch-testing.sqlite')
        ->and(configurationIssueLines($configuration))->toBe(["firewatch.database: {$reason}; using /app/storage/firewatch/firewatch-testing.sqlite"]);
})->with([
    'empty' => ['', '"" is not a file path'],
    'not a string' => [5, '5 is not a file path'],
    'in memory' => [':memory:', '":memory:" is an in-memory database, not a file path'],
    'file URI' => ['file:store.sqlite?mode=ro', '"file:store.sqlite?mode=ro" is a URI, not a file path'],
    'NUL byte' => ["store\0.sqlite", '"store\u0000.sqlite" contains a NUL byte'],
    '4097 bytes' => ['/'.str_repeat('a', 4096), '"/'.str_repeat('a', 59).'" is longer than 4096 bytes'],
    'relative under the public directory' => ['public/firewatch.sqlite', '"public/firewatch.sqlite" is under the public directory'],
    'absolute under the public directory' => ['/app/public/firewatch.sqlite', '"/app/public/firewatch.sqlite" is under the public directory'],
    'under the public directory through a dot segment' => ['storage/../public/store.sqlite', '"storage/../public/store.sqlite" is under the public directory'],
]);

it('refuses a Windows store path under the public directory whatever its case', function (string $path) {
    $normaliser = new ConfigurationNormaliser(basePath: 'C:\\app', publicPath: 'C:\\app\\public', storagePath: 'C:\\app\\storage', environment: 'testing');

    $configuration = $normaliser->resolve(['database' => $path]);

    expect($configuration->database)->toBe('C:\\app\\storage/firewatch/firewatch-testing.sqlite')
        ->and(configurationIssueLines($configuration))->toHaveCount(1)
        ->and(configurationIssueLines($configuration)[0])->toContain('is under the public directory');
})->with([
    'drive letter in another case' => ['c:\\app\\public\\firewatch.sqlite'],
    'directories in another case' => ['C:\\APP\\Public\\firewatch.sqlite'],
    'forward slashes in another case' => ['c:/APP/public/firewatch.sqlite'],
]);

it('keeps a POSIX store path that differs from the public directory only in case', function () {
    $configuration = configurationNormaliser()->resolve(['database' => '/app/Public/firewatch.sqlite']);

    expect($configuration->database)->toBe('/app/Public/firewatch.sqlite')
        ->and($configuration->issues)->toBe([]);
});

it('normalises the deploy identity', function (mixed $value, ?string $expected) {
    $configuration = configurationNormaliser()->resolve(['deploy' => $value]);

    expect($configuration->deploy)->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'whitespace' => ['   ', null],
    'trimmed' => [' v1.2.0 ', 'v1.2.0'],
    '255 bytes ending in a multibyte character' => [str_repeat('a', 253).'é', str_repeat('a', 253).'é'],
    '256 bytes cut before a multibyte character' => [str_repeat('a', 254).'é', str_repeat('a', 254)],
    'invalid UTF-8 bytes dropped' => ["v1\xFF\xFE-rc", 'v1-rc'],
]);

it('treats a deploy identity that is not a string as unset', function () {
    $configuration = configurationNormaliser()->resolve(['deploy' => 5]);

    expect($configuration->deploy)->toBeNull()
        ->and(configurationIssueLines($configuration))->toBe(['firewatch.deploy: 5 is not a string; using unset']);
});

it('never throws, whatever the configuration holds', function (mixed $value) {
    $raw = [
        'enabled' => $value,
        'environments' => $value,
        'database' => $value,
        'busy_timeout' => $value,
        'retention' => ['age' => $value, 'records' => $value],
        'deploy' => $value,
        'capture' => ['logs' => $value, 'request_payload' => $value, 'redact_payload_fields' => $value, 'redact_headers' => $value],
        'budgets' => [$value, ['type' => 'request', 'path' => $value, 'duration' => $value]],
    ];

    $resolve = fn () => configurationNormaliser()->resolve($raw);

    expect($resolve)->not->toThrow(Throwable::class);
})->with([
    'object' => [new stdClass],
    'closure' => [fn () => true],
    'infinity' => [INF],
    'nested array' => [[[['deep']]]],
    'invalid UTF-8' => ["\xFF\xFE"],
]);
