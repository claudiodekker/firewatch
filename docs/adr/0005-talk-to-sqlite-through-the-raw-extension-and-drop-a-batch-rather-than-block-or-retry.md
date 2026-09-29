# Talk to SQLite through the raw extension and drop a batch rather than block or retry

Firewatch reads and writes its store through PHP's `SQLite3` extension on its own connection, not Laravel's database layer, and a batch that cannot be written in 500 ms is dropped and recorded beside the store instead of retried, spooled or thrown.

The store writes from inside the host application's request at its terminating stage, so the store's own queries must not be observed by Nightwatch, PDO's 60 s default wait is unsafe, and the authorizer needed for read-only SQL exists on `SQLite3` from PHP 8.3 but on PDO only from 8.5. A lost batch of dev telemetry is stated in diagnostics, while a blocked or failing host request is a defect in a tool meant to be invisible.
