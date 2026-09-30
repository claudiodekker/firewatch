<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firewatch Enabled
    |--------------------------------------------------------------------------
    |
    | This value turns Firewatch off without removing it. It is the only
    | on/off switch; everything Firewatch forces onto Nightwatch follows it.
    |
    */

    'enabled' => env('FIREWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Capture Environments
    |--------------------------------------------------------------------------
    |
    | Firewatch captures only in these environments, given as a list or a
    | comma-separated string of names. Anywhere else it steps aside and
    | Nightwatch behaves as if Firewatch were not installed.
    |
    */

    'environments' => env('FIREWATCH_ENVIRONMENTS', 'local,testing'),

    /*
    |--------------------------------------------------------------------------
    | Store Path
    |--------------------------------------------------------------------------
    |
    | This is the path of the SQLite file that holds the telemetry. Give it a
    | directory of its own, outside the public directory. A relative path
    | resolves against the base path of the application.
    |
    */

    'database' => env('FIREWATCH_DATABASE', storage_path('firewatch/firewatch.sqlite')),

    /*
    |--------------------------------------------------------------------------
    | Busy Timeout
    |--------------------------------------------------------------------------
    |
    | This is how many milliseconds (0 to 5000) capture waits for a busy
    | store. A batch that can't be written in time is dropped rather than
    | stalling the request, and 0 drops it at once.
    |
    */

    'busy_timeout' => env('FIREWATCH_BUSY_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Records older than the age (digits then s, m, h, d or w) are pruned,
    | and the store keeps at most the given number of records (1 to
    | 10000000).
    |
    */

    'retention' => [
        'age' => env('FIREWATCH_RETENTION_AGE', '7d'),
        'records' => env('FIREWATCH_RETENTION_RECORDS', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy Identity
    |--------------------------------------------------------------------------
    |
    | When set, this value is written to Nightwatch as the deploy identity.
    | Left unset, Nightwatch resolves the deploy the way it always does.
    |
    */

    'deploy' => env('FIREWATCH_DEPLOY'),

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | These options control whether logs and request payloads are captured.
    | Redaction is relaxed, so payloads are stored as sent. You may list
    | payload fields and request headers to redact on top of that, as a
    | list or a comma-separated string.
    |
    */

    'capture' => [
        'logs' => env('FIREWATCH_CAPTURE_LOGS', true),
        'request_payload' => env('FIREWATCH_CAPTURE_REQUEST_PAYLOAD', true),
        'redact_payload_fields' => env('FIREWATCH_REDACT_PAYLOAD_FIELDS', ''),
        'redact_headers' => env('FIREWATCH_REDACT_HEADERS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Budgets
    |--------------------------------------------------------------------------
    |
    | A budget is a ceiling on the cost of one kind of execution: request,
    | command, job-attempt or scheduled-task. Match requests by methods and
    | path, and other types by name. Give a duration ceiling in milliseconds,
    | a memory ceiling in MB, or both.
    |
    */

    'budgets' => [
        // ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
        // ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
    ],

];
