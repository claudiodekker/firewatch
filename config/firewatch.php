<?php

return [

    // Turns Firewatch off without removing it; FIREWATCH_ENABLED is the only on/off switch.
    'enabled' => env('FIREWATCH_ENABLED', true),

    // Environments Firewatch captures in, as a list or a comma-separated string; anywhere else it steps aside.
    'environments' => env('FIREWATCH_ENVIRONMENTS', 'local,testing'),

    // Path of the store file, in a directory of its own; a relative path resolves against the base path.
    'database' => env('FIREWATCH_DATABASE', storage_path('firewatch/firewatch.sqlite')),

    // Milliseconds the capture side waits for a busy store before dropping the batch (0 to 5000).
    'busy_timeout' => env('FIREWATCH_BUSY_TIMEOUT', 300),

    'retention' => [

        // Records older than this are pruned: digits then s, m, h, d or w.
        'age' => env('FIREWATCH_RETENTION_AGE', '7d'),

        // The store keeps at most this many records (1 to 10000000).
        'records' => env('FIREWATCH_RETENTION_RECORDS', 100000),

    ],

    // Deploy identity written to Nightwatch; unset leaves Nightwatch's own resolution.
    'deploy' => env('FIREWATCH_DEPLOY'),

    'capture' => [

        // Captures log entries through a wrapped default log channel.
        'logs' => env('FIREWATCH_CAPTURE_LOGS', true),

        // Captures request payloads; redaction is relaxed, so the store holds them as sent.
        'request_payload' => env('FIREWATCH_CAPTURE_REQUEST_PAYLOAD', true),

        // Payload fields to redact on top of Nightwatch's relaxed defaults, as a list or a comma-separated string.
        'redact_payload_fields' => env('FIREWATCH_REDACT_PAYLOAD_FIELDS', ''),

        // Request headers to redact on top of Nightwatch's relaxed defaults, as a list or a comma-separated string.
        'redact_headers' => env('FIREWATCH_REDACT_HEADERS', ''),

    ],

    // Performance budgets per execution type: request, command, job-attempt or scheduled-task, with optional
    // matchers (methods and path for requests, name otherwise) and a duration (ms) and/or memory (MB) ceiling.
    'budgets' => [
        // ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
        // ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
    ],

];
