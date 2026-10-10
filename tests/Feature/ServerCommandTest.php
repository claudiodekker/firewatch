<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Registrar;

function firewatchVersion(): string
{
    return InstalledVersions::getPrettyVersion('claudiodekker/firewatch') ?? 'dev';
}

it('lists the tools under a header, one line each', function () {
    $result = Artisan::call('firewatch:server', ['--list' => true]);

    expect($result)->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            __('firewatch::messages.listing', ['version' => firewatchVersion(), 'count' => 12]),
            '  overview     Entry point: what is wrong now.',
            '  rank         Groups of one type (routes, queries, jobs...) worst first by a measure: what is slow, hea…',
            '  occurrences  Individual records, newest first by default, for at least one selector: `group`, `type`, …',
            '  execution    One request, command, job attempt or scheduled task in full: outcome, stages, headers and…',
            '  trace        One trace\'s executions in start order and each queued job\'s lineage: dispatch, attempts, …',
            '  detect       Problem shapes, worst evidence first, with default thresholds: `n-plus-one`, a read query…',
            '  actor        One signed-in person and the window\'s work tied to them.',
            '  compare      Each group before against after: did my change help?',
            '  trend        A measure over equal time buckets: did it rise, fall or hold, and where did it peak?',
            '  query        Last resort: your own read-only SQL.',
            '  describe     What Firewatch holds and how to query it.',
            '  fingerprint  The group id of something read in source, and whether the store holds it, so a miss is no…',
            '',
        ]));
});

it('lists a tool\'s first sentence, cut at 90 characters', function (string $description, string $expected) {
    app()->bind(Overview::class, fn () => new class($description) extends Overview
    {
        public function __construct(protected string $text)
        {
            //
        }

        public function description(): string
        {
            return $this->text;
        }
    });

    Artisan::call('firewatch:server', ['--list' => true]);

    expect(explode("\n", Artisan::output())[1])->toBe("  overview     {$expected}");
})->with([
    '90 characters' => ['description' => str_repeat('a', 89).'. Never listed.', 'expected' => str_repeat('a', 89).'.'],
    '91 characters' => ['description' => str_repeat('a', 90).'. Never listed.', 'expected' => str_repeat('a', 89).'…'],
    'ending in a question mark' => ['description' => 'What is wrong? Never listed.', 'expected' => 'What is wrong?'],
    'ending in an exclamation mark' => ['description' => 'Start here! Never listed.', 'expected' => 'Start here!'],
    'a full stop inside a word' => ['description' => 'Reads config.php first. Never listed.', 'expected' => 'Reads config.php first.'],
    'no sentence end' => ['description' => 'Entry point', 'expected' => 'Entry point'],
]);

it('lists the tools as JSON with the server name and version', function () {
    $result = Artisan::call('firewatch:server', ['--list' => true, '--json' => true]);

    expect($result)->toBe(0)
        ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'server' => ['name' => 'firewatch', 'version' => firewatchVersion()],
            'tools' => [
                [
                    'name' => 'overview',
                    'description' => __('firewatch::messages.tools.overview'),
                    'inputSchema' => ['properties' => ['since' => ['description' => windowArgument('since'), 'type' => 'string'], 'until' => ['description' => windowArgument('until'), 'type' => 'string'], 'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string']], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'rank',
                    'description' => __('firewatch::messages.tools.rank'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.rank_group_argument'), 'type' => 'string'],
                        'matching' => ['description' => __('firewatch::messages.rank_matching_argument'), 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.rank_by_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('rank', 100, 20), 'type' => 'integer'],
                        'cursor' => ['description' => __('firewatch::messages.cursor_argument'), 'type' => 'string'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'occurrences',
                    'description' => __('firewatch::messages.tools.occurrences'),
                    'inputSchema' => ['properties' => [
                        'group' => ['description' => __('firewatch::messages.occurrences_group_argument'), 'type' => 'string'],
                        'type' => ['description' => __('firewatch::messages.occurrences_type_argument'), 'type' => 'string'],
                        'execution_id' => ['description' => __('firewatch::messages.occurrences_execution_id_argument'), 'type' => 'string'],
                        'trace_id' => ['description' => __('firewatch::messages.occurrences_trace_id_argument'), 'type' => 'string'],
                        'job_id' => ['description' => __('firewatch::messages.occurrences_job_id_argument'), 'type' => 'string'],
                        'user_id' => ['description' => __('firewatch::messages.occurrences_user_id_argument'), 'type' => 'string'],
                        'order' => ['description' => __('firewatch::messages.occurrences_order_argument'), 'type' => 'string'],
                        'method' => ['description' => __('firewatch::messages.occurrences_method_argument'), 'type' => 'string'],
                        'status' => ['description' => __('firewatch::messages.occurrences_status_argument'), 'type' => 'string'],
                        'outcome' => ['description' => __('firewatch::messages.occurrences_outcome_argument'), 'type' => 'string'],
                        'level' => ['description' => __('firewatch::messages.occurrences_level_argument'), 'type' => 'string'],
                        'slower_than_ms' => ['description' => __('firewatch::messages.occurrences_slower_than_ms_argument'), 'type' => 'number'],
                        'at_or_above' => ['description' => __('firewatch::messages.occurrences_at_or_above_argument'), 'type' => 'string'],
                        'matching' => ['description' => __('firewatch::messages.occurrences_matching_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('occurrences', 100, 20), 'type' => 'integer'],
                        'cursor' => ['description' => __('firewatch::messages.cursor_argument'), 'type' => 'string'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'execution',
                    'description' => __('firewatch::messages.tools.execution'),
                    'inputSchema' => ['properties' => [
                        'execution_id' => ['description' => __('firewatch::messages.execution_id_argument'), 'type' => 'string'],
                        'type' => ['description' => __('firewatch::messages.execution_type_argument'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('execution', 100, 50), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'trace',
                    'description' => __('firewatch::messages.tools.trace'),
                    'inputSchema' => ['properties' => [
                        'trace_id' => ['description' => __('firewatch::messages.trace_id_argument'), 'type' => 'string'],
                        'job_id' => ['description' => __('firewatch::messages.trace_job_id_argument'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('trace', 100, 50), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'detect',
                    'description' => __('firewatch::messages.tools.detect'),
                    'inputSchema' => ['properties' => [
                        'shape' => ['description' => __('firewatch::messages.detect_shape_argument'), 'enum' => ['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'cache', 'memory'], 'type' => 'string'],
                        'threshold' => ['description' => __('firewatch::messages.detect_threshold_argument'), 'type' => 'number'],
                        'group' => ['description' => __('firewatch::messages.detect_group_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('detect', 100, 20), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'actor',
                    'description' => __('firewatch::messages.tools.actor'),
                    'inputSchema' => ['properties' => [
                        'who' => ['description' => __('firewatch::messages.actor_who_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('actor', 100, 20), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object', 'required' => ['who']],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'compare',
                    'description' => __('firewatch::messages.tools.compare'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.compare_group_argument'), 'type' => 'string'],
                        'split_at' => ['description' => __('firewatch::messages.compare_split_at_argument'), 'type' => 'string'],
                        'deploy_before' => ['description' => __('firewatch::messages.compare_deploy_before_argument'), 'type' => 'string'],
                        'deploy_after' => ['description' => __('firewatch::messages.compare_deploy_after_argument'), 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.compare_by_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since', 'compare'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until', 'compare'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('compare', 100, 20), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'trend',
                    'description' => __('firewatch::messages.tools.trend'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.trend_group_argument'), 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.trend_by_argument'), 'type' => 'string'],
                        'buckets' => ['description' => __('firewatch::messages.trend_buckets_argument'), 'type' => 'integer'],
                        'since' => ['description' => windowArgument('since', 'trend'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until', 'trend'), 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'query',
                    'description' => __('firewatch::messages.tools.query'),
                    'inputSchema' => ['properties' => [
                        'sql' => ['description' => __('firewatch::messages.query_sql_argument'), 'type' => 'string'],
                        'limit' => ['description' => limitArgument('query', 500, 50), 'type' => 'integer'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'describe',
                    'description' => __('firewatch::messages.tools.describe'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.describe_type_argument'), 'type' => 'string'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'fingerprint',
                    'description' => __('firewatch::messages.tools.fingerprint'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.fingerprint_arguments.type'), 'type' => 'string'],
                        'methods' => ['description' => __('firewatch::messages.fingerprint_arguments.methods'), 'items' => ['type' => 'string'], 'type' => 'array'],
                        'path' => ['description' => __('firewatch::messages.fingerprint_arguments.path'), 'type' => 'string'],
                        'domain' => ['description' => __('firewatch::messages.fingerprint_arguments.domain'), 'type' => 'string'],
                        'name' => ['description' => __('firewatch::messages.fingerprint_arguments.name'), 'type' => 'string'],
                        'cron' => ['description' => __('firewatch::messages.fingerprint_arguments.cron'), 'type' => 'string'],
                        'timezone' => ['description' => __('firewatch::messages.fingerprint_arguments.timezone'), 'type' => 'string'],
                        'repeat_seconds' => ['description' => __('firewatch::messages.fingerprint_arguments.repeat_seconds'), 'type' => 'integer'],
                        'connection' => ['description' => __('firewatch::messages.fingerprint_arguments.connection'), 'type' => 'string'],
                        'sql' => ['description' => __('firewatch::messages.fingerprint_arguments.sql'), 'type' => 'string'],
                        'driver' => ['description' => __('firewatch::messages.fingerprint_arguments.driver'), 'type' => 'string'],
                        'store' => ['description' => __('firewatch::messages.fingerprint_arguments.store'), 'type' => 'string'],
                        'key' => ['description' => __('firewatch::messages.fingerprint_arguments.key'), 'type' => 'string'],
                        'host' => ['description' => __('firewatch::messages.fingerprint_arguments.host'), 'type' => 'string'],
                        'class' => ['description' => __('firewatch::messages.fingerprint_arguments.class'), 'type' => 'string'],
                        'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
            ],
        ]);
});

it('refuses --json without --list', function () {
    $result = Artisan::call('firewatch:server', ['--json' => true]);

    expect($result)->toBe(1)
        ->and(trim(Artisan::output()))->toBe(__('firewatch::messages.json_requires_list'));
});

it('lists the tools without creating anything where the store would be', function (array $options) {
    $path = app(Configuration::class)->database;

    Artisan::call('firewatch:server', $options);

    expect(dirname($path))->not->toBeDirectory();
})->with([
    'human' => ['options' => ['--list' => true]],
    'JSON' => ['options' => ['--list' => true, '--json' => true]],
]);

it('registers no laravel/mcp handle for mcp:start to run', function () {
    $servers = app(Registrar::class)->servers();

    expect($servers)->toBe([]);
});
