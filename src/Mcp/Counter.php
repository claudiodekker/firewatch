<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
enum Counter: string
{
    case QUERIES = 'queries';
    case EXCEPTIONS = 'exceptions';
    case LOGS = 'logs';
    case CACHE_EVENTS = 'cache_events';
    case MAIL = 'mail';
    case NOTIFICATIONS = 'notifications';
    case OUTGOING_REQUESTS = 'outgoing_requests';
    case JOBS_QUEUED = 'jobs_queued';

    /**
     * Get the record type of the children the counter counts.
     */
    public function type(): RecordType
    {
        return match ($this) {
            self::QUERIES => RecordType::QUERY,
            self::EXCEPTIONS => RecordType::EXCEPTION,
            self::LOGS => RecordType::LOG,
            self::CACHE_EVENTS => RecordType::CACHE_EVENT,
            self::MAIL => RecordType::MAIL,
            self::NOTIFICATIONS => RecordType::NOTIFICATION,
            self::OUTGOING_REQUESTS => RecordType::OUTGOING_REQUEST,
            self::JOBS_QUEUED => RecordType::QUEUED_JOB,
        };
    }

    /**
     * Get the plural noun the accounting names the children with.
     */
    public function noun(): string
    {
        return __("firewatch::messages.accounting_nouns.{$this->value}");
    }
}
