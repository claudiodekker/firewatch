<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Bounds;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Registrar;

it('lists the tools under a header, one line each', function () {
    $result = Artisan::call('firewatch:server', ['--list' => true]);

    expect($result)->toBe(0)
        ->and(Artisan::output())->toBe(implode(PHP_EOL, [
            __('firewatch::messages.listing', ['version' => firewatchVersion(), 'count' => 12]),
            '  overview     Entry point: what is wrong now.',
            '  rank         Groups of one type (routes, queries, jobs...) worst first by a measure: what is slow, hea…',
            '  detect       Problem shapes, worst evidence first, with default thresholds: `n-plus-one`, a read query…',
            '  occurrences  Individual records, newest first by default, for at least one selector: `group`, `type`, …',
            '  execution    One request, command, job attempt or scheduled task in full: outcome, stages, headers and…',
            '  trace        One trace\'s executions in start order and each queued job\'s lineage: dispatch, attempts, …',
            '  actor        One signed-in person and the window\'s work tied to them.',
            '  compare      Each group before against after: did my change help?',
            '  trend        A measure over equal time buckets: did it rise, fall or hold, and where did it peak?',
            '  query        Last resort: your own read-only SQL.',
            '  describe     What Firewatch holds and how to query it.',
            '  fingerprint  The group id of something read in source and whether the store holds it.',
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

    expect(explode(PHP_EOL, Artisan::output())[1])->toBe("  overview     {$expected}");
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
                    'inputSchema' => ['properties' => ['since' => ['description' => windowArgument('since', listsForms: true), 'type' => 'string'], 'until' => ['description' => windowArgument('until'), 'type' => 'string'], 'scope' => ['default' => 'all', 'enum' => ['executions', 'between', 'all'], 'type' => 'string'], 'by_deploy' => ['description' => __('firewatch::messages.overview_by_deploy_argument'), 'default' => false, 'type' => 'boolean'], 'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string']], 'type' => 'object'],
                    'annotations' => ['title' => 'Overview', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'rank',
                    'description' => __('firewatch::messages.tools.rank'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'enum' => ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'], 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.rank_group_argument'), 'type' => 'string'],
                        'within' => ['description' => __('firewatch::messages.rank_within_argument'), 'type' => 'string'],
                        'matching' => ['description' => __('firewatch::messages.rank_matching_argument'), 'minLength' => 1, 'maxLength' => 200, 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.rank_by_argument'), 'enum' => ['p95_duration', 'p50_duration', 'max_duration', 'total_duration', 'occurrences', 'p95_memory', 'max_memory', 'last_seen', 'queries'], 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'scope' => ['default' => 'all', 'enum' => ['executions', 'between', 'all'], 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.rank'), 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'cursor' => ['description' => __('firewatch::messages.cursor_argument'), 'type' => 'string'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Rank', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
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
                        'scope' => ['default' => 'all', 'enum' => ['executions', 'between', 'all'], 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.detect'), 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Detect', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'occurrences',
                    'description' => __('firewatch::messages.tools.occurrences'),
                    'inputSchema' => ['properties' => [
                        'group' => ['description' => __('firewatch::messages.occurrences_group_argument'), 'type' => 'string'],
                        'type' => ['enum' => ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'], 'type' => 'string'],
                        'execution_id' => ['description' => __('firewatch::messages.occurrences_execution_id_argument'), 'type' => 'string'],
                        'trace_id' => ['description' => __('firewatch::messages.occurrences_trace_id_argument'), 'type' => 'string'],
                        'job_id' => ['description' => __('firewatch::messages.occurrences_job_id_argument'), 'type' => 'string'],
                        'user_id' => ['description' => __('firewatch::messages.occurrences_user_id_argument'), 'type' => 'string'],
                        'order' => ['description' => __('firewatch::messages.occurrences_order_argument'), 'default' => 'recent', 'enum' => ['recent', 'slowest', 'memory', 'queries', 'run'], 'type' => 'string'],
                        'method' => ['description' => __('firewatch::messages.occurrences_method_argument'), 'type' => 'string'],
                        'status' => ['description' => __('firewatch::messages.occurrences_status_argument'), 'type' => 'string'],
                        'outcome' => ['description' => __('firewatch::messages.occurrences_outcome_argument'), 'enum' => ['processed', 'failed', 'released', 'skipped'], 'type' => 'string'],
                        'level' => ['description' => __('firewatch::messages.occurrences_level_argument'), 'enum' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], 'type' => 'string'],
                        'slower_than_ms' => ['description' => __('firewatch::messages.occurrences_slower_than_ms_argument'), 'type' => 'number'],
                        'at_or_above' => ['description' => __('firewatch::messages.occurrences_at_or_above_argument'), 'enum' => ['median', 'p95'], 'type' => 'string'],
                        'matching' => ['description' => __('firewatch::messages.occurrences_matching_argument'), 'minLength' => 1, 'maxLength' => 200, 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'scope' => ['default' => 'all', 'enum' => ['executions', 'between', 'all'], 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.occurrences'), 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'cursor' => ['description' => __('firewatch::messages.cursor_argument'), 'type' => 'string'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Occurrences', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'execution',
                    'description' => __('firewatch::messages.tools.execution'),
                    'inputSchema' => ['properties' => [
                        'execution_id' => ['description' => __('firewatch::messages.execution_id_argument'), 'type' => 'string'],
                        'type' => ['description' => __('firewatch::messages.execution_type_argument'), 'enum' => ['request', 'command', 'job-attempt', 'scheduled-task'], 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.execution'), 'default' => 50, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Execution', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'trace',
                    'description' => __('firewatch::messages.tools.trace'),
                    'inputSchema' => ['properties' => [
                        'trace_id' => ['description' => __('firewatch::messages.trace_id_argument'), 'type' => 'string'],
                        'job_id' => ['description' => __('firewatch::messages.trace_job_id_argument'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.trace'), 'default' => 50, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Trace', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'actor',
                    'description' => __('firewatch::messages.tools.actor'),
                    'inputSchema' => ['properties' => [
                        'who' => ['description' => __('firewatch::messages.actor_who_argument'), 'type' => 'string'],
                        'since' => ['description' => windowArgument('since'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.actor'), 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object', 'required' => ['who']],
                    'annotations' => ['title' => 'Actor', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'compare',
                    'description' => __('firewatch::messages.tools.compare'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'enum' => ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'], 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.compare_group_argument'), 'type' => 'string'],
                        'split_at' => ['description' => __('firewatch::messages.compare_split_at_argument'), 'type' => 'string'],
                        'deploy_before' => ['description' => __('firewatch::messages.compare_deploy_before_argument'), 'type' => 'string'],
                        'deploy_after' => ['description' => __('firewatch::messages.compare_deploy_after_argument'), 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.compare_by_argument'), 'enum' => ['p95_duration', 'p50_duration', 'max_duration', 'total_duration', 'occurrences', 'p95_memory', 'p50_memory', 'max_memory', 'queries'], 'type' => 'string'],
                        'since' => ['description' => windowArgument('since', 'compare'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until', 'compare'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.compare'), 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Compare', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'trend',
                    'description' => __('firewatch::messages.tools.trend'),
                    'inputSchema' => ['properties' => [
                        'type' => ['description' => __('firewatch::messages.grouped_type_argument'), 'enum' => ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'], 'type' => 'string'],
                        'group' => ['description' => __('firewatch::messages.trend_group_argument'), 'type' => 'string'],
                        'by' => ['description' => __('firewatch::messages.trend_by_argument'), 'default' => 'occurrences', 'enum' => ['occurrences', 'max_duration', 'avg_duration', 'total_duration', 'max_memory'], 'type' => 'string'],
                        'buckets' => ['description' => __('firewatch::messages.trend_buckets_argument'), 'default' => 12, 'minimum' => 2, 'maximum' => 60, 'type' => 'integer'],
                        'since' => ['description' => windowArgument('since', 'trend'), 'type' => 'string'],
                        'until' => ['description' => windowArgument('until', 'trend'), 'type' => 'string'],
                        'deploy' => ['description' => __('firewatch::messages.deploy_argument'), 'type' => 'string'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Trend', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'query',
                    'description' => __('firewatch::messages.tools.query', ['characters' => number_format(Bounds::CELL_CHARACTERS)]),
                    'inputSchema' => ['properties' => [
                        'sql' => ['description' => __('firewatch::messages.query_sql_argument'), 'type' => 'string'],
                        'limit' => ['description' => __('firewatch::messages.limit_argument.query'), 'default' => 50, 'minimum' => 1, 'maximum' => 500, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Query', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'describe',
                    'description' => __('firewatch::messages.tools.describe'),
                    'inputSchema' => ['properties' => [
                        'type' => ['enum' => ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job', 'user'], 'type' => 'string'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object'],
                    'annotations' => ['title' => 'Describe', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
                [
                    'name' => 'fingerprint',
                    'description' => __('firewatch::messages.tools.fingerprint'),
                    'inputSchema' => ['properties' => [
                        'type' => ['enum' => ['request', 'command', 'job-attempt', 'queued-job', 'scheduled-task', 'query', 'cache-event', 'outgoing-request', 'mail', 'notification', 'exception'], 'type' => 'string'],
                        'methods' => ['items' => ['type' => 'string'], 'type' => 'array'],
                        'path' => ['type' => 'string'],
                        'domain' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'cron' => ['type' => 'string'],
                        'timezone' => ['type' => 'string'],
                        'repeat_seconds' => ['default' => 0, 'enum' => [0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30], 'type' => 'integer'],
                        'connection' => ['type' => 'string'],
                        'sql' => ['type' => 'string'],
                        'driver' => ['type' => 'string'],
                        'store' => ['type' => 'string'],
                        'key' => ['type' => 'string'],
                        'host' => ['type' => 'string'],
                        'class' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'line' => ['minimum' => 1, 'type' => 'integer'],
                        'format' => ['default' => 'markdown', 'enum' => ['markdown', 'json'], 'type' => 'string'],
                    ], 'type' => 'object', 'required' => ['type']],
                    'annotations' => ['title' => 'Fingerprint', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
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
