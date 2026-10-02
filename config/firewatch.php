<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firewatch Enabled
    |--------------------------------------------------------------------------
    |
    | This value turns Firewatch off without removing the package. It is the
    | only on/off switch, and every value it forces on Nightwatch follows
    | it. When it is false, Firewatch records nothing into its store.
    |
    */

    'enabled' => env('FIREWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Capture Environments
    |--------------------------------------------------------------------------
    |
    | Firewatch captures only in these environments, as a list or a comma-
    | separated string of names. In any other environment it steps aside
    | and Nightwatch behaves as if Firewatch had not been installed.
    |
    */

    'environments' => env('FIREWATCH_ENVIRONMENTS', 'local,testing'),

    /*
    |--------------------------------------------------------------------------
    | Store Path
    |--------------------------------------------------------------------------
    |
    | This is the path of the SQLite file that holds the telemetry. Give it
    | its own directory, outside of the public directory. Relative paths
    | resolve against the base path of the Laravel application in use.
    |
    */

    'database' => env('FIREWATCH_DATABASE', storage_path('firewatch/firewatch.sqlite')),

    /*
    |--------------------------------------------------------------------------
    | Busy Timeout
    |--------------------------------------------------------------------------
    |
    | This value is how many milliseconds (0 to 5000) capture waits for a
    | busy store. A batch that isn't written in time is dropped, rather
    | than stalling the request. A value of 0 drops it straight away.
    |
    */

    'busy_timeout' => env('FIREWATCH_BUSY_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Records older than the age (digits then s, m, h, d or w) are pruned,
    | and the store keeps at most the given number of records, from 1 up
    | to 10000000. Pruning runs after every successful capture write.
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
    | When set, this value is written to Nightwatch as the deploy identity
    | so answers can compare deploys. Left unset, Nightwatch resolves it
    | the way it always does, from its own configuration and platform.
    |
    */

    'deploy' => env('FIREWATCH_DEPLOY'),

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | These options control whether logs and request payloads are captured.
    | Redaction is relaxed, so payloads are stored as sent. List payload
    | fields and request headers here to redact them on top of that.
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
    | A budget caps the cost of a request, command, job-attempt or scheduled
    | task. Requests match by methods and path, other types by their name.
    | Give it a duration ceiling in ms, a memory ceiling in MB, or both.
    |
    */

    'budgets' => [],

];
