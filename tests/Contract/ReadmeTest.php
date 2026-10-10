<?php

use ClaudioDekker\Firewatch\Console\Doctor\Check;
use ClaudioDekker\Firewatch\Console\Doctor\CheckStatus;
use ClaudioDekker\Firewatch\Mcp\Detectors\DetectorName;
use Illuminate\Support\Facades\Artisan;

const README_FILE = __DIR__.'/../../README.md';

const CONFIG_FILE = __DIR__.'/../../config/firewatch.php';

function readmeSection(string $heading): string
{
    preg_match('/^## '.preg_quote($heading, '/').'\n(.*?)(?=^## |\z)/ms', file_get_contents(README_FILE), $match);

    expect($match)->not->toBe([], "The README has no section [{$heading}].");

    return $match[1];
}

/**
 * Get the rows of the first table of a section, without backticks and without the header.
 *
 * @return list<list<string>>
 */
function readmeTable(string $heading): array
{
    preg_match('/(?:^\|.+\|[ \t]*\n)+/m', readmeSection($heading), $block);

    expect($block)->not->toBe([], "The README section [{$heading}] has no table.");

    $cells = fn (string $line) => array_map(fn (string $cell) => trim(str_replace('`', '', $cell)), explode('|', trim($line, '| ')));

    return array_slice(array_map($cells, explode("\n", trim($block[0]))), 2);
}

/**
 * @return array<string, mixed>
 */
function flattenConfig(array $config, string $prefix = ''): array
{
    $flat = [];

    foreach ($config as $key => $value) {
        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            $flat += flattenConfig($value, "{$prefix}{$key}.");
        } else {
            $flat[$prefix.$key] = $value;
        }
    }

    return $flat;
}

/**
 * Load the shipped configuration file with exactly the given Firewatch variables set.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function loadShippedConfig(array $environment = []): array
{
    $names = array_unique(array_merge(
        array_filter(array_keys($_ENV + $_SERVER), fn ($name) => str_starts_with($name, 'FIREWATCH_')),
        array_keys($environment),
    ));
    $before = array_map(fn ($name) => $_ENV[$name] ?? $_SERVER[$name] ?? null, array_combine($names, $names));

    $set = function (string $name, ?string $value) {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    };

    foreach ($names as $name) {
        $set($name, $environment[$name] ?? null);
    }

    try {
        return flattenConfig(require CONFIG_FILE);
    } finally {
        foreach ($before as $name => $value) {
            $set($name, $value);
        }
    }
}

function renderDefault(mixed $value): string
{
    return match (true) {
        $value === null => 'unset',
        $value === '' || $value === [] => 'empty',
        is_bool($value) => $value ? 'true' : 'false',
        is_string($value) => str_replace('\\', '/', str_starts_with($value, base_path().DIRECTORY_SEPARATOR) ? substr($value, strlen(base_path()) + 1) : $value),
        default => (string) $value,
    };
}

test('the configuration table lists exactly the keys of the shipped file', function () {
    $keys = array_column(readmeTable('Configuration'), 0);

    expect($keys)->toEqualCanonicalizing(array_keys(loadShippedConfig()));
});

test('each environment variable in the configuration table changes exactly its key', function () {
    $base = loadShippedConfig();
    $rows = readmeTable('Configuration');

    foreach ($rows as [$key, $variable]) {
        if ($variable === 'none') {
            continue;
        }

        $loaded = loadShippedConfig([$variable => 'sentinel']);
        $changed = array_keys(array_filter($loaded, fn ($value, $name) => $value !== $base[$name], ARRAY_FILTER_USE_BOTH));

        expect($changed)->toBe([$key], "Setting {$variable} should change only {$key}.");
    }
});

test('the configuration table names every environment variable the file reads', function () {
    preg_match_all("/env\\('([A-Z_]+)'/", file_get_contents(CONFIG_FILE), $reads);

    $named = array_filter(array_column(readmeTable('Configuration'), 1), fn (string $variable) => $variable !== 'none');

    expect($named)->toEqualCanonicalizing($reads[1]);
});

test('each default in the configuration table is the default of the shipped file', function () {
    $config = loadShippedConfig();

    foreach (readmeTable('Configuration') as [$key, , $default]) {
        expect($default)->toBe(renderDefault($config[$key]), "The default of {$key} differs.");
    }
});

test('the tool table lists exactly the tools the server lists', function () {
    $listed = array_column(toolListing()['tools'], 'name');

    expect(array_column(readmeTable('Tools'), 0))->toEqualCanonicalizing($listed);
});

test('every tool the question table names exists', function () {
    $listed = array_column(toolListing()['tools'], 'name');

    expect(array_unique(array_column(readmeTable('Ask your assistant'), 1)))->each->toBeIn($listed);
});

test('the detector line names exactly the detectors', function () {
    preg_match('/^Detectors: (.+)$/m', readmeSection('Tools'), $line);

    preg_match_all('/`([a-z-]+)`/', $line[1] ?? '', $names);

    expect($names[1])->toEqualCanonicalizing(array_column(DetectorName::cases(), 'value'));
});

test('the command table lists exactly the firewatch commands and their options', function () {
    $registered = array_filter(Artisan::all(), fn ($command, string $name) => str_starts_with($name, 'firewatch:'), ARRAY_FILTER_USE_BOTH);
    $rows = readmeTable('Commands and diagnostics');

    expect(array_column($rows, 0))->toEqualCanonicalizing(array_keys($registered));

    foreach ($rows as [$name, $options]) {
        preg_match_all('/--([a-z-]+)/', $options, $named);

        expect($named[1])->toEqualCanonicalizing(array_keys($registered[$name]->getNativeDefinition()->getOptions()), "The options of {$name} differ.");
    }
});

test('the doctor paragraph names exactly the statuses a check can have', function () {
    preg_match_all('/`\\[([a-z]+)\\]`/', readmeSection('Commands and diagnostics'), $labels);

    expect($labels[1])->toEqualCanonicalizing(array_column(CheckStatus::cases(), 'value'));
});

test('every doctor check the section names exists', function () {
    preg_match_all('/`([a-z]+(?:-[a-z]+)*)`/', readmeSection('Commands and diagnostics'), $named);

    expect($named[1])->not->toBe([])->each->toBeIn(array_column(Check::cases(), 'value'));
});
