<?php

use Illuminate\Contracts\Console\Kernel;

$base = realpath($argv[1] ?? '') ?: exit("usage: dev-only-probe.php <application-path>\n");

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$packages = array_keys(require $base.'/bootstrap/cache/packages.php');
sort($packages);

$ingesting = 'Laravel\\Nightwatch\\Events\\IngestingEvents';
$config = $app['config']->get('nightwatch');
$commands = array_values(array_filter(array_keys($kernel->all()), fn (string $name) => str_starts_with($name, 'firewatch:')));
sort($commands);

echo json_encode([
    'packages' => $packages,
    'firewatchCommands' => $commands,
    'ingestingEventsListeners' => class_exists($ingesting) ? count($app['events']->getListeners($ingesting)) : null,
    'nightwatchConfig' => $config,
    'storeFiles' => glob($base.'/storage/firewatch/*') ?: [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
