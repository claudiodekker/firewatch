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
     * Get the record types of the events.
     *
     * @return list<self>
     */
    public static function events(): array
    {
        $events = [];

        foreach (self::cases() as $type) {
            if ($type !== self::USER) {
                $events[] = $type;
            }
        }

        return $events;
    }

    /**
     * The source of a request's executions.
     */
    protected const REQUEST_SOURCE = 'request';

    /**
     * The source of a command's executions.
     */
    protected const COMMAND_SOURCE = 'command';

    /**
     * The source of a job attempt's executions.
     */
    protected const JOB_SOURCE = 'job';

    /**
     * The source of a scheduled task's executions.
     */
    protected const SCHEDULE_SOURCE = 'schedule';

    /**
     * The wire fields every record carries, with the name they are stored under and their accepted JSON types.
     */
    protected const ENVELOPE = [
        't' => ['type', 'string'],
        'v' => ['v', 'integer'],
        'timestamp' => ['started_at', 'number'],
        'deploy' => ['deploy', 'string'],
        'server' => ['server', 'string'],
        '_group' => ['group_hash', 'string'],
        'trace_id' => ['trace_id', 'string'],
    ];

    /**
     * The wire fields that tie a child event to its execution, with the name they are stored under and their accepted JSON types.
     */
    protected const EXECUTION_SCOPE = [
        'execution_source' => ['source', 'string'],
        'execution_id' => ['execution_id', 'string'],
        'execution_preview' => [null, 'string'],
        'execution_stage' => ['execution_stage', 'string'],
        'user' => ['user_id', 'string'],
    ];

    /**
     * The counters and measurements every execution carries, with the name they are stored under and their accepted JSON types.
     */
    protected const EXECUTION_TOTALS = [
        'exceptions' => ['exceptions', 'integer'],
        'logs' => ['logs', 'integer'],
        'queries' => ['queries', 'integer'],
        'lazy_loads' => ['lazy_loads', 'integer'],
        'jobs_queued' => ['jobs_queued', 'integer'],
        'mail' => ['mail', 'integer'],
        'notifications' => ['notifications', 'integer'],
        'outgoing_requests' => ['outgoing_requests', 'integer'],
        'files_read' => ['files_read', 'integer'],
        'files_written' => ['files_written', 'integer'],
        'cache_events' => ['cache_events', 'integer'],
        'hydrated_models' => ['hydrated_models', 'integer'],
        'peak_memory_usage' => ['peak_memory_usage', 'integer'],
        'exception_preview' => ['exception_preview', 'string'],
        'context' => ['context', 'string'],
    ];

    /**
     * Get the wire fields of the type, by the name they are stored under; a null name is not stored.
     *
     * @return array<string, string|null>
     */
    public function fields(): array
    {
        return array_map(fn (array $field) => $field[0], $this->contract());
    }

    /**
     * Get the JSON types each wire field of the type accepts.
     *
     * @return array<string, list<string>>
     */
    public function acceptedTypes(): array
    {
        return array_map(fn (array $field) => explode('|', $field[1]), $this->contract());
    }

    /**
     * Determine if the contract table knows the given version of the type.
     */
    public function hasVersion(mixed $version): bool
    {
        return $version === $this->version();
    }

    /**
     * Get the version of the type the contract table was built from.
     */
    protected function version(): int
    {
        return match ($this) {
            self::EXCEPTION => 3,
            default => 1,
        };
    }

    /**
     * Get the type's contract: each wire field with the name it is stored under and its accepted JSON types, separated by "|".
     *
     * @return array<string, array{string|null, string}>
     */
    protected function contract(): array
    {
        return match ($this) {
            self::REQUEST => [
                ...self::ENVELOPE,
                'user' => ['user_id', 'string'],
                'method' => ['method', 'string'],
                'url' => ['url', 'string'],
                'route_name' => ['route_name', 'string'],
                'route_methods' => ['route_methods', 'array'],
                'route_domain' => ['route_domain', 'string'],
                'route_path' => ['route_path', 'string'],
                'route_action' => ['route_action', 'string'],
                'ip' => ['ip', 'string'],
                'duration' => ['duration', 'integer'],
                'status_code' => ['status_code', 'integer'],
                'request_size' => ['request_size', 'integer'],
                'response_size' => ['response_size', 'integer'],
                'bootstrap' => ['bootstrap', 'integer'],
                'before_middleware' => ['before_middleware', 'integer'],
                'action' => ['action', 'integer'],
                'render' => ['render', 'integer'],
                'after_middleware' => ['after_middleware', 'integer'],
                'sending' => ['sending', 'integer'],
                'terminating' => ['terminating', 'integer'],
                ...self::EXECUTION_TOTALS,
                'headers' => ['headers', 'string'],
                'payload' => ['payload', 'string'],
            ],
            self::COMMAND => [
                ...self::ENVELOPE,
                'class' => ['class', 'string'],
                'name' => ['name', 'string'],
                'command' => ['command', 'string'],
                'exit_code' => ['exit_code', 'integer'],
                'duration' => ['duration', 'integer'],
                'bootstrap' => ['bootstrap', 'integer'],
                'action' => ['action', 'integer'],
                'terminating' => ['terminating', 'integer'],
                ...self::EXECUTION_TOTALS,
            ],
            self::JOB_ATTEMPT => [
                ...self::ENVELOPE,
                'user' => ['user_id', 'string'],
                'job_id' => ['job_id', 'string'],
                'attempt_id' => ['execution_id', 'string'],
                'attempt' => ['attempt', 'integer'],
                'name' => ['name', 'string'],
                'connection' => ['connection', 'string'],
                'queue' => ['queue', 'string'],
                'status' => ['status', 'string'],
                'duration' => ['duration', 'integer'],
                ...self::EXECUTION_TOTALS,
            ],
            self::SCHEDULED_TASK => [
                ...self::ENVELOPE,
                'name' => ['name', 'string'],
                'cron' => ['cron', 'string'],
                'timezone' => ['timezone', 'string'],
                'repeat_seconds' => ['repeat_seconds', 'integer'],
                'without_overlapping' => ['without_overlapping', 'boolean'],
                'on_one_server' => ['on_one_server', 'boolean'],
                'run_in_background' => ['run_in_background', 'boolean'],
                'even_in_maintenance_mode' => ['even_in_maintenance_mode', 'boolean'],
                'status' => ['status', 'string'],
                'duration' => ['duration', 'integer'],
                ...self::EXECUTION_TOTALS,
            ],
            self::QUERY => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'sql' => ['sql', 'string'],
                'file' => ['file', 'string'],
                'line' => ['line', 'integer'],
                'duration' => ['duration', 'integer'],
                'connection' => ['connection', 'string'],
                'connection_type' => ['connection_type', 'string'],
            ],
            self::EXCEPTION => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'class' => ['class', 'string'],
                'file' => ['file', 'string'],
                'line' => ['line', 'integer'],
                'message' => ['message', 'string'],
                'code' => ['code', 'string'],
                'trace' => ['trace', 'string'],
                'handled' => ['handled', 'boolean'],
                'php_version' => ['php_version', 'string'],
                'laravel_version' => ['laravel_version', 'string'],
            ],
            self::LOG => [
                't' => ['type', 'string'],
                'v' => ['v', 'integer'],
                'timestamp' => ['started_at', 'number'],
                'deploy' => ['deploy', 'string'],
                'server' => ['server', 'string'],
                'trace_id' => ['trace_id', 'string'],
                ...self::EXECUTION_SCOPE,
                'level' => ['level', 'string'],
                'message' => ['message', 'string'],
                'context' => ['context', 'string'],
                'extra' => ['extra', 'string'],
            ],
            self::CACHE_EVENT => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'store' => ['store', 'string'],
                'key' => ['key', 'string'],
                'type' => ['event', 'string'],
                'duration' => ['duration', 'integer'],
                'ttl' => ['ttl', 'integer'],
            ],
            self::MAIL => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'mailer' => ['mailer', 'string'],
                'class' => ['class', 'string'],
                'subject' => ['subject', 'string'],
                'to' => ['to', 'integer'],
                'cc' => ['cc', 'integer'],
                'bcc' => ['bcc', 'integer'],
                'attachments' => ['attachments', 'integer'],
                'duration' => ['duration', 'integer'],
                'failed' => ['failed', 'boolean'],
            ],
            self::NOTIFICATION => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'channel' => ['channel', 'string'],
                'class' => ['class', 'string'],
                'duration' => ['duration', 'integer'],
                'failed' => ['failed', 'boolean'],
            ],
            self::OUTGOING_REQUEST => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'host' => ['host', 'string'],
                'method' => ['method', 'string'],
                'url' => ['url', 'string'],
                'duration' => ['duration', 'integer'],
                'request_size' => ['request_size', 'integer'],
                'response_size' => ['response_size', 'integer'],
                'status_code' => ['status_code', 'integer'],
            ],
            self::QUEUED_JOB => [
                ...self::ENVELOPE,
                ...self::EXECUTION_SCOPE,
                'job_id' => ['job_id', 'string'],
                'name' => ['name', 'string'],
                'connection' => ['connection', 'string'],
                'queue' => ['queue', 'string'],
                'duration' => ['duration', 'integer'],
            ],
            self::USER => [
                't' => ['type', 'string'],
                'v' => ['v', 'integer'],
                'timestamp' => ['started_at', 'number'],
                'id' => ['id', 'string'],
                'name' => ['name', 'string'],
                'username' => ['username', 'string'],
            ],
        };
    }

    /**
     * Get the fields Firewatch adds to the type's data, which are never on the wire.
     *
     * @return list<string>
     */
    public function addedFields(): array
    {
        return match ($this) {
            self::QUERY => ['bindings'],
            default => [],
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
            self::REQUEST => self::REQUEST_SOURCE,
            self::COMMAND => self::COMMAND_SOURCE,
            self::JOB_ATTEMPT => self::JOB_SOURCE,
            self::SCHEDULED_TASK => self::SCHEDULE_SOURCE,
            default => null,
        };
    }

    /**
     * Determine if Nightwatch stamps the type's records when they end rather than when they start.
     */
    public function isStampedAtEnd(): bool
    {
        return match ($this) {
            self::MAIL, self::NOTIFICATION, self::QUEUED_JOB => true,
            default => false,
        };
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
