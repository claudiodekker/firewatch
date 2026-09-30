<?php

use ClaudioDekker\Firewatch\Store\Schema;

/**
 * @return list<string>
 */
function viewColumns(string $view): array
{
    $connection = new SQLite3(':memory:');

    foreach ((new Schema)->statements() as $statement) {
        $connection->exec($statement);
    }

    $result = $connection->query("PRAGMA table_info({$view})");
    $columns = [];

    while (($column = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        $columns[] = $column['name'];
    }

    return $columns;
}

test('the store shape is pinned to its schema version', function () {
    $ddl = implode(";\n", (new Schema)->statements());

    expect([Schema::VERSION, hash('sha256', $ddl)])->toBe([1, '8de3a2c18efef6d128af1ef7b065dcc60a1d5f138f65fc32a6a61bf3ebf2b3c2'], 'the store shape changed: bump the schema version');
});

test('each record view has the common columns of its type, then its contract fields', function (string $view, array $columns) {
    expect(viewColumns($view))->toBe($columns);
})->with([
    'requests' => ['view' => 'requests', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'user_id', 'deploy', 'server', 'method', 'url', 'route_name', 'route_methods', 'route_domain', 'route_path', 'route_action', 'ip', 'status_code', 'request_size', 'response_size', 'bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context', 'headers', 'payload', 'data']],
    'commands' => ['view' => 'commands', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'user_id', 'deploy', 'server', 'class', 'name', 'command', 'exit_code', 'bootstrap', 'action', 'terminating', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context', 'data']],
    'job attempts' => ['view' => 'job_attempts', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'job_id', 'user_id', 'deploy', 'server', 'attempt', 'name', 'connection', 'queue', 'status', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context', 'data']],
    'scheduled tasks' => ['view' => 'scheduled_tasks', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'user_id', 'deploy', 'server', 'name', 'cron', 'timezone', 'repeat_seconds', 'without_overlapping', 'on_one_server', 'run_in_background', 'even_in_maintenance_mode', 'status', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'peak_memory_usage', 'exception_preview', 'context', 'data']],
    'queries' => ['view' => 'queries', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'sql', 'file', 'line', 'connection', 'connection_type', 'data']],
    'exceptions' => ['view' => 'exceptions', 'columns' => ['id', 'v', 'started_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'class', 'file', 'line', 'message', 'code', 'trace', 'handled', 'php_version', 'laravel_version', 'data']],
    'logs' => ['view' => 'logs', 'columns' => ['id', 'v', 'started_at', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'level', 'message', 'context', 'extra', 'data']],
    'cache events' => ['view' => 'cache_events', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'store', 'key', 'event', 'ttl', 'data']],
    'mail' => ['view' => 'mail', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'mailer', 'class', 'subject', 'to', 'cc', 'bcc', 'attachments', 'failed', 'data']],
    'notifications' => ['view' => 'notifications', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'channel', 'class', 'failed', 'data']],
    'outgoing requests' => ['view' => 'outgoing_requests', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'user_id', 'deploy', 'server', 'execution_stage', 'host', 'method', 'url', 'request_size', 'response_size', 'status_code', 'data']],
    'queued jobs' => ['view' => 'queued_jobs', 'columns' => ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'execution_source', 'job_id', 'user_id', 'deploy', 'server', 'execution_stage', 'name', 'connection', 'queue', 'data']],
]);

test('creates one record view per event type and none for the user record', function () {
    $views = array_filter((new Schema)->statements(), fn (string $statement) => str_starts_with($statement, 'CREATE VIEW'));

    expect($views)->toHaveCount(12);
});
