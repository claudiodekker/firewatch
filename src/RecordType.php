<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
enum RecordType: string
{
    case REQUEST = 'request';
    case COMMAND = 'command';
    case JOB_ATTEMPT = 'job-attempt';
    case SCHEDULED_TASK = 'scheduled-task';
    case QUERY = 'query';
    case EXCEPTION = 'exception';
    case LOG = 'log';
    case CACHE_EVENT = 'cache-event';
    case MAIL = 'mail';
    case NOTIFICATION = 'notification';
    case OUTGOING_REQUEST = 'outgoing-request';
    case QUEUED_JOB = 'queued-job';
    case USER = 'user';

    /**
     * The wire fields every record carries, by the name they are stored under.
     */
    protected const ENVELOPE = [
        't' => 'type',
        'v' => 'v',
        'timestamp' => 'started_at',
        'deploy' => 'deploy',
        'server' => 'server',
        '_group' => 'group_hash',
        'trace_id' => 'trace_id',
    ];

    /**
     * The wire fields that tie a child event to its execution, by the name they are stored under.
     */
    protected const EXECUTION_SCOPE = [
        'execution_source' => 'source',
        'execution_id' => 'execution_id',
        'execution_preview' => null,
        'execution_stage' => 'execution_stage',
        'user' => 'user_id',
    ];

    /**
     * The counters and measurements every execution carries.
     */
    protected const EXECUTION_TOTALS = [
        'exceptions' => 'exceptions',
        'logs' => 'logs',
        'queries' => 'queries',
        'lazy_loads' => 'lazy_loads',
        'jobs_queued' => 'jobs_queued',
        'mail' => 'mail',
        'notifications' => 'notifications',
        'outgoing_requests' => 'outgoing_requests',
        'files_read' => 'files_read',
        'files_written' => 'files_written',
        'cache_events' => 'cache_events',
        'hydrated_models' => 'hydrated_models',
        'peak_memory_usage' => 'peak_memory_usage',
        'exception_preview' => 'exception_preview',
        'context' => 'context',
    ];

    /**
     * Get the wire fields of the type, by the name they are stored under; a null name is not stored.
     *
     * @return array<string, string|null>
     */
    public function fields(): array
    {
        return match ($this) {
            self::REQUEST => [
                ...self::ENVELOPE,
                'user' => 'user_id',
                'method' => 'method',
                'url' => 'url',
                'route_name' => 'route_name',
                'route_methods' => 'route_methods',
                'route_domain' => 'route_domain',
                'route_path' => 'route_path',
                'route_action' => 'route_action',
                'ip' => 'ip',
                'duration' => 'duration',
                'status_code' => 'status_code',
                'request_size' => 'request_size',
                'response_size' => 'response_size',
                'bootstrap' => 'bootstrap',
                'before_middleware' => 'before_middleware',
                'action' => 'action',
                'render' => 'render',
                'after_middleware' => 'after_middleware',
                'sending' => 'sending',
                'terminating' => 'terminating',
                ...self::EXECUTION_TOTALS,
                'headers' => 'headers',
                'payload' => 'payload',
            ],
            self::COMMAND => [
                ...self::ENVELOPE,
                'class' => 'class',
                'name' => 'name',
                'command' => 'command',
                'exit_code' => 'exit_code',
                'duration' => 'duration',
                'bootstrap' => 'bootstrap',
                'action' => 'action',
                'terminating' => 'terminating',
                ...self::EXECUTION_TOTALS,
            ],
            self::JOB_ATTEMPT => [
                ...self::ENVELOPE,
                'user' => 'user_id',
                'job_id' => 'job_id',
                'attempt_id' => 'execution_id',
                'attempt' => 'attempt',
                'name' => 'name',
                'connection' => 'connection',
                'queue' => 'queue',
                'status' => 'status',
                'duration' => 'duration',
                ...self::EXECUTION_TOTALS,
            ],
            self::SCHEDULED_TASK => [
                ...self::ENVELOPE,
                'name' => 'name',
                'cron' => 'cron',
                'timezone' => 'timezone',
                'repeat_seconds' => 'repeat_seconds',
                'without_overlapping' => 'without_overlapping',
                'on_one_server' => 'on_one_server',
                'run_in_background' => 'run_in_background',
                'even_in_maintenance_mode' => 'even_in_maintenance_mode',
                'status' => 'status',
                'duration' => 'duration',
                ...self::EXECUTION_TOTALS,
            ],
            self::QUERY => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'sql' => 'sql',
                'file' => 'file',
                'line' => 'line',
                'duration' => 'duration',
                'connection' => 'connection',
                'connection_type' => 'connection_type',
            ],
            self::EXCEPTION => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'class' => 'class',
                'file' => 'file',
                'line' => 'line',
                'message' => 'message',
                'code' => 'code',
                'trace' => 'trace',
                'handled' => 'handled',
                'php_version' => 'php_version',
                'laravel_version' => 'laravel_version',
            ],
            self::LOG => [
                't' => 'type',
                'v' => 'v',
                'timestamp' => 'started_at',
                'deploy' => 'deploy',
                'server' => 'server',
                'trace_id' => 'trace_id',
                ...self::EXECUTION_SCOPE,
                'level' => 'level',
                'message' => 'message',
                'context' => 'context',
                'extra' => 'extra',
            ],
            self::CACHE_EVENT => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'store' => 'store',
                'key' => 'key',
                'type' => 'event',
                'duration' => 'duration',
                'ttl' => 'ttl',
            ],
            self::MAIL => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'mailer' => 'mailer',
                'class' => 'class',
                'subject' => 'subject',
                'to' => 'to',
                'cc' => 'cc',
                'bcc' => 'bcc',
                'attachments' => 'attachments',
                'duration' => 'duration',
                'failed' => 'failed',
            ],
            self::NOTIFICATION => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'channel' => 'channel',
                'class' => 'class',
                'duration' => 'duration',
                'failed' => 'failed',
            ],
            self::OUTGOING_REQUEST => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'host' => 'host',
                'method' => 'method',
                'url' => 'url',
                'duration' => 'duration',
                'request_size' => 'request_size',
                'response_size' => 'response_size',
                'status_code' => 'status_code',
            ],
            self::QUEUED_JOB => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'job_id' => 'job_id',
                'name' => 'name',
                'connection' => 'connection',
                'queue' => 'queue',
                'duration' => 'duration',
            ],
            self::USER => [
                't' => 'type',
                'v' => 'v',
                'timestamp' => 'started_at',
                'id' => 'id',
                'name' => 'name',
                'username' => 'username',
            ],
        };
    }

    /**
     * Get the wire fields of the type that carry a JSON string, decoded before they are stored.
     *
     * @return list<string>
     */
    public function jsonFields(): array
    {
        return match ($this) {
            self::REQUEST => ['context', 'headers', 'payload'],
            self::COMMAND, self::JOB_ATTEMPT, self::SCHEDULED_TASK => ['context'],
            self::EXCEPTION => ['trace'],
            self::LOG => ['context', 'extra'],
            default => [],
        };
    }

    /**
     * Get the source of the type's executions, or null for a type that is not an execution.
     */
    public function source(): ?string
    {
        return match ($this) {
            self::REQUEST => 'request',
            self::COMMAND => 'command',
            self::JOB_ATTEMPT => 'job',
            self::SCHEDULED_TASK => 'schedule',
            default => null,
        };
    }

    /**
     * Determine if Nightwatch stamps the type's records when they end rather than when they start.
     */
    public function isStampedAtEnd(): bool
    {
        return in_array($this, [self::MAIL, self::NOTIFICATION, self::QUEUED_JOB], strict: true);
    }

    /**
     * Get the name of the type's record view, or null for a type that has none.
     */
    public function view(): ?string
    {
        return match ($this) {
            self::REQUEST => 'requests',
            self::COMMAND => 'commands',
            self::JOB_ATTEMPT => 'job_attempts',
            self::SCHEDULED_TASK => 'scheduled_tasks',
            self::QUERY => 'queries',
            self::EXCEPTION => 'exceptions',
            self::LOG => 'logs',
            self::CACHE_EVENT => 'cache_events',
            self::MAIL => 'mail',
            self::NOTIFICATION => 'notifications',
            self::OUTGOING_REQUEST => 'outgoing_requests',
            self::QUEUED_JOB => 'queued_jobs',
            self::USER => null,
        };
    }
}
