# Nightwatch sensors, ingest and record contract: findings

Facts only, no decisions. Sources: scratch installs of `laravel/nightwatch` v1.30.2 (current, 2026-09-18), v1.29.0, v1.28.7, v1.24.0, v1.1.0 and v1.0.0, and the official docs (https://nightwatch.laravel.com/docs/, index at `/docs/llms.txt`). Legend: **[code]** read in source (paths relative to the package root, v1.30.2 unless noted); **[docs]** official documentation; **[inferred]**. Nothing was executed against a live application.

## 1. Versions and the supportable range

- [code] All 1.x require PHP `^8.2`. Laravel constraint: v1.0.0 `illuminate/console` + `illuminate/support` `^10|^11`; v1.0.3 `laravel/framework` `^10|^11`; v1.1.0 adds `^12`; v1.24.0 (2026-02-27) adds `^13`; v1.30.2 `^10|^11|^12|^13`. So the oldest release covering Laravel 12 is v1.1.0, and the oldest covering 12 and 13 is v1.24.0 [inferred].
- [docs] requirements: Laravel 10+, PHP 8.2+ (13 not mentioned).
- Architecture by release [code]:
  - v1.1.0 to 1.4.x: `src/Ingest.php` implements `Contracts/LocalIngest::write(string)` and requires a prebuilt `client/entry.php`; records are typed objects with public `$v`/`$t`; the records buffer is hard-capped at 500; config has only `enabled`, `token`, `deployment`, `server`, `ingest`, `error_log_channel` (no sampling, filtering or redaction).
  - From v1.5.0: `src/Payload.php`, `Contracts/Ingest`, no `client/`.
  - From v1.10.0: `RedactsRecords`.
  - v1.24.0 to v1.28.7: identical record field sets to v1.30.2 (all 14 sensors and the fatal-error record diffed) and the same `Contracts/Ingest`.
  - v1.28.7 to v1.30.2 differs only by: `Events/IngestingEvents` (added v1.29.0; `eventCount()` added v1.30.0), a Dispatcher on `Ingest`, and `RecordsBuffer::all()`.
  - Other v1.24 to v1.28 differences: no skipping of non-reportable exceptions, no queue-enum parsing, no Cloud/Forge/Vapor deploy fallback, no `DeployCommand`.

## 2. Discovery, enabling and boot

- [code] `composer.json` `extra.laravel`: provider `Laravel\Nightwatch\NightwatchServiceProvider`, alias `Nightwatch` facade.
- [code] `register()` (`src/NightwatchServiceProvider.php`): captures the start time (`LARAVEL_START` or `REQUEST_TIME_FLOAT`), runs `Compatibility::boot`, decides request versus console, merges config, registers bindings, and installs hooks only if `enabled`. Throwables are stored and forwarded in `boot()` to `Nightwatch::unrecoverableExceptionOccurred`; nothing escapes.
- [code] The only gate is `config('nightwatch.enabled')` (`NIGHTWATCH_ENABLED`, default true). There is no `APP_ENV` gate and no token check (a null token hashes the empty string). `tokenHash = substr(xxh128(token), 0, 7)`.
- [code] `isRequest = !runningInConsole() || env NIGHTWATCH_FORCE_REQUEST`. The console kind comes from the first `CommandStarting` (`Hooks/CommandStartingListener`): `queue:work`, `queue:listen`, `horizon:work`, `vapor:work` install job hooks (source `job`); `schedule:run`, `schedule:work`, `vapor:schedule` install scheduler hooks (source `schedule`); `help`, `inspire`, `schedule:finish` install nothing; anything else installs command hooks (source `command`; only if the kernel is the Foundation Console Kernel).
- [code] Octane `RequestReceived` calls `prepareForRequest`; Livewire 2/3 hooks rewrite `route_action` to the component class.
- [code] Global hooks: listeners for `QueryExecuted`, `JobQueueing`/`JobQueued`, `NotificationSending`/`Sent`, `MessageSending`/`Sent`, 11 cache events, `Logout`, `RouteMatched`, `PreparingResponse`, `ResponsePrepared`, `RequestHandled`, `Terminating`; `callAfterResolving` on the ExceptionHandler (adds a reportable callback) and the Http Factory (Guzzle global middleware); `Queue::createPayloadUsing` (adds `payload.nightwatch.job_id`); `Context::dehydrating` (hidden `nightwatch_user_id`); the HTTP kernel gets `GlobalMiddleware` prepended, `Sample` in the middleware priority, and `whenRequestLifecycleIsLongerThan(-1, ...)` as the end hook.
- [code] Log channel `logging.channels.nightwatch` (custom, `Factories\Logger`, level from `filtering.log_level`) is registered only if not already defined and is not added to any stack automatically. [docs] logs: add it to `LOG_STACK` or set `LOG_CHANNEL=nightwatch`.
- [code] Commands: `nightwatch:agent` (requires the closed `agent/build/agent.phar`; options `--listen-on`, `--auth-*-timeout`, `--ingest-*-timeout`, `--server`, `--silent`), `nightwatch:status` (sends PING, expects an ack), `nightwatch:deploy`. [docs] default agent port 2407, one agent per application.

## 3. Sensors and wire records (`src/Sensors/*.php`)

**Envelope**: `v`, `t`, `timestamp` (float seconds), `deploy`, `server`, `_group` (xxh128), `trace_id`. Execution-scoped records add `execution_source` (`request|command|job|schedule`), `execution_id`, `execution_preview`, `execution_stage` (enum string), `user`. `trace_id`, `execution_id`, `user` and `preview` may be `LazyValue` (JsonSerializable, resolved at encode time). String limits (bytes, `substr`): tinyText 255, text 65,535, mediumText 16,777,215. Durations are microseconds; `peak_memory_usage` is bytes.

Types (`t` / `v` / trigger / extra fields):

- **request** /1/ end of an HTTP request: `method`, `url` (scheme+host+base+path+?query), `route_name`, `route_methods` (sorted array), `route_domain`, `route_path`, `route_action`, `ip`, `duration`, `status_code`, `request_size`, `response_size`; stage durations `bootstrap`, `before_middleware`, `action`, `render`, `after_middleware`, `sending`, `terminating`; counters `exceptions`, `logs`, `queries`, `lazy_loads`, `jobs_queued`, `mail`, `notifications`, `outgoing_requests`, `files_read`, `files_written`, `cache_events`, `hydrated_models`; `peak_memory_usage`, `exception_preview`, `context` (JSON string of `Context::all`, 65 KB cap), `headers` (JSON string), `payload` (string). `_group` = methods, domain, path.
- **command** /1/ non-worker, non-scheduler artisan: `class`, `name`, `command` (argv joined), `exit_code` (0..255), `duration`, stages `bootstrap`/`action`/`terminating`, same counters, `peak_memory_usage`, `exception_preview`, `context`. No `execution_*`/`user`. `_group` = name.
- **query** /1/ `QueryExecuted`: `sql` (mediumText; bindings not read), `file`, `line` (first non-vendor frame), `duration`, `connection`, `connection_type` (`read|write|''`; Laravel 12.45.0+). `timestamp` = now minus duration. `_group` normalizes `in (...)` and `insert values` for mariadb, mysql, pgsql, sqlite, sqlsrv, singlestore.
- **exception** /3/ reportable callback, `Nightwatch::report`, scheduled-task failure: `class`, `file`, `line`, `message`, `code`, `trace` (mediumText JSON string: list of `{file, source, code}`; `code` = `{lineNo: text}` with 5 lines of context, at most 10 application frames when `capture_exception_source_code`), `handled`, `php_version`, `laravel_version`. ViewException is unwrapped. Fatal errors: `execution_id` `''`, `trace` `''`, `handled` false.
- **log** /1/ Monolog handler: `level`, `message`, `context` (JSON string), `extra` (JSON string). No `_group`.
- **queued-job** /1/ `JobQueued`: `job_id` (`payload.nightwatch.job_id` or a uuid), `name`, `connection`, `queue`, `duration`.
- **job-attempt** /1/ `JobProcessed|JobReleasedAfterException|JobFailed` (not the sync connection): `job_id`, `attempt_id`, `attempt`, `name`, `connection`, `queue`, `status` (`released|failed|processed`), `duration`, counters, `peak_memory_usage`, `exception_preview`, `context`. No `execution_*`.
- **cache-event** /1/ outcome events only: `store`, `key`, `type` (`hit|miss|write|write-failure|delete|delete-failure`), `duration` (µs), `ttl` (s, writes only). No values.
- **mail** /1/ `MessageSent` (skipped for notification mail): `mailer`, `class`, `subject`, `to`/`cc`/`bcc`/`attachments` (counts), `duration`, `failed` (always false).
- **notification** /1/: `channel`, `class`, `duration`, `failed` (always false).
- **outgoing-request** /1/ Laravel Http client: `host`, `method`, `url` (userinfo stripped), `duration`, `request_size`, `response_size`, `status_code`.
- **scheduled-task** /1/: `name`, `cron`, `timezone`, `repeat_seconds`, `without_overlapping`, `on_one_server`, `run_in_background`, `even_in_maintenance_mode`, `status` (`processed|failed|skipped`), `duration`, counters, `peak_memory_usage`, `exception_preview`, `context`. No `execution_*`.
- **user** /1/ at the end of a request if a user was resolved: only `v`, `t`, `timestamp`, `id`, `name`, `username`. Not counted as an event.
- **Stages** are state, not records: request `bootstrap`, `before_middleware`, `action`, `render`, `after_middleware`, `sending`, `terminating`, `end`; command `bootstrap`, `action`, `terminating`, `end`.
- **Linkage** [code]: `trace_id` propagates to jobs via hidden Context `nightwatch_trace_id` (Laravel 11+; polyfilled earlier); `execution_id` = the execution instance; `job_id` links queued-job to job-attempt.
- **Request payload** [code]: non-empty only on status 500: `NOT_ENABLED` / `UNSUPPORTED_CONTENT_TYPE` / `SERIALIZATION_FAILED` error JSON, else redacted params plus `_nightwatch_files` (`{originalName, size, error}`). [docs] requests agrees.

## 4. Ingest frame, buffering, events and seams

- [code] `Contracts\Ingest` (`@internal`): `write(array)`, `writeNow(array)`, `ping()`, `shouldDigest()` (deprecated), `shouldDigestWhenBufferIsFull(bool)`, `digest()`, `flush()`. The only implementation is the final `Ingest`. It is not bound in the container: the provider does `new Ingest(...)` inline into `Core::$ingest` (a public `@internal` property of the final class `Core`, bound via `$app->instance(Core::class)`). Ingest constructor: `transmitTo` (`tcp://` + `ingest.uri`), `connectionTimeout`, `timeout`, public `$streamFactory` (`SocketStreamFactory` to `stream_socket_client`), `RecordsBuffer`, `tokenHash`, `Dispatcher`.
- [code] Buffer: size `ingest.event_buffer` (500); when full, `write` drops the oldest (`array_shift`); `Ingest::write` digests when full if `shouldDigestWhenBufferIsFull`.
- [code] Frame (`src/Payload.php`, version `v1`): `{len}:v1:{tokenHash}:{json}`, `len = strlen('v1') + 1 + strlen(hash) + 1 + strlen(json)` (excludes the `len:` prefix). `json` is a list of record arrays (flags `INVALID_UTF8_SUBSTITUTE | PRESERVE_ZERO_FRACTION | THROW_ON_ERROR | UNESCAPED_SLASHES | UNESCAPED_UNICODE`). A ping is the same frame with the text `PING`. The agent must answer exactly `2:OK` (4 bytes) or a RuntimeException is thrown. One TCP connection per transmit. No compression in this frame in `src/` (`ext-zlib` is required in `composer.json`; its use is not visible, likely agent-side [inferred]).
- [code] Failures thrown in `finishExecution` are caught and forwarded to `Nightwatch::unrecoverableExceptionOccurred` (silent unless `Nightwatch::handleUnrecoverableExceptionsUsing(cb)` is set).
- [code] Flush points (`Core::finishExecution`): sampled calls `ingest->digest()`; unsampled calls `ingest->flush()` (buffer discarded, nothing sent). Callers: request end hook (stage End; `captureUser` writes the user record, then `request()` writes the request record); command end hook; worker `Looping`/`WorkerStopping`/`CommandFinished` (`queue:work`) finish and `dontSample`; `JobProcessing` calls `prepareForJob`; `JobPopping` calls `prepareForNextJob`; job attempts are written in `JobAttemptListener`; the scheduler handles each task (`Starting` prepares; `Finished`/`Skipped`/`Failed` call `scheduledTask()` and finish).
- [code] `writeNow` sends a single-record frame immediately after dispatching the event; used for unhandled exceptions when sampled, and for fatal errors.
- [code] `Laravel\Nightwatch\Events\IngestingEvents` (v1.29.0+): `public readonly array $records`, `eventCount()` (excludes `t=user`; v1.30.0+). Dispatched with `Dispatcher::until()` in `digest()` (whole buffer) and `writeNow()` (single record) before transmit; an empty list is not dispatched. Any listener returning `false` cancels the transmit (the buffer is flushed in `digest`). During dispatch an `ingesting` flag makes `write`/`writeNow` no-ops. [docs] filtering ("Rate limiting") says the same.
- [inferred] A non-false listener return still lets the socket write happen; failure is swallowed if no agent listens. In v1.5.0 to 1.28.7 no event exists; the seams are the writable `Core::$ingest`, `Ingest::$streamFactory`, or a TCP server on `ingest.uri` speaking the frame.
- [code] Per-event order: hook, `Core::<type>()` (enabled, `filtering.ignore_*`, paused), the sensor builds the record object plus a resolver, reject callbacks (true drops), redact callbacks (mutate the record), the resolver builds the array from the mutated record, `Ingest::write`, buffer, the `finishExecution` sampling gate, `IngestingEvents`, transmit. Exceptions: redact, then `writeNow` (sampled and unhandled) or `write`.

## 5. Configuration; sampling, filtering and redaction (`config/nightwatch.php`)

Keys, env vars and defaults [code], descriptions [docs environment-variables]:

- `enabled` `NIGHTWATCH_ENABLED` true; `token` `NIGHTWATCH_TOKEN` null; `deployment` `NIGHTWATCH_DEPLOY` (fallbacks `LARAVEL_CLOUD_DEPLOY_UUID`, `FORGE_DEPLOY_COMMIT`, `VAPOR_COMMIT_HASH`); `server` `NIGHTWATCH_SERVER` `gethostname()`.
- `capture_exception_source_code` true; `capture_request_payload` false.
- `redact_payload_fields` `_token,password,password_confirmation` (matching string values become `[N bytes redacted]`, recursive, exact key); `redact_headers` `Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN` (`php-auth-user`, `php-auth-pw`, `php-auth-digest` are always removed).
- `sampling.requests|commands|exceptions|scheduled_tasks` (`NIGHTWATCH_{REQUEST,COMMAND,EXCEPTION,SCHEDULED_TASK}_SAMPLE_RATE`, 1.0).
- `filtering.ignore_cache_events|ignore_mail|ignore_notifications|ignore_outgoing_requests|ignore_queries` (`NIGHTWATCH_IGNORE_*`, false); `filtering.log_level` (`NIGHTWATCH_LOG_LEVEL`, then `LOG_LEVEL`, then `debug`).
- `ingest.uri` `127.0.0.1:2407`, `timeout` 0.5, `connection_timeout` 0.5, `event_buffer` 500 (`NIGHTWATCH_INGEST_*`).

**Sampling** [code, `Concerns/CapturesState.php`]: `sample($rate)` = `random_int / PHP_INT_MAX <= rate` (out-of-range gives 0); it sets Ingest digest-when-full and the hidden Context `nightwatch_should_sample`.
- Requests: `GlobalMiddleware` uses `sampling.requests`; per-route `Http\Middleware\Sample::rate|always|never` or `Nightwatch::sample()`.
- Commands: default vendor commands are never sampled (`auth:clear-resets`, `config:cache`, `horizon:snapshot|status|supervisor`, `inertia:start-ssr`, `invoke-serialized-closure`, `model:prune`, `nightwatch:agent|status`, `octane:status`, `queue:monitor`, `reverb:start`, `schedule:list`) unless `captureDefaultVendorCommands()`; otherwise the Context flag, else `sampling.commands`.
- Scheduled tasks: per-task `Console\Sample` via `->tap`, else `sampling.scheduled_tasks`.
- Jobs: inherit the parent Context flag (default sampled); no config key.
- Exceptions: if unsampled, `report()` resamples with `sampling.exceptions` and calls `writeNow`.
- [inferred] Buffered non-exception records of an unsampled execution are still discarded at finish; sensors still run and counters still increment when unsampled.

**Filtering** [code, `Concerns/RejectsRecords.php`]: reject callbacks (returning true drops): `rejectQueries`, `rejectCacheEvents`, `rejectMail`, `rejectNotifications`, `rejectOutgoingRequests`, `rejectQueuedJobs`; `rejectCacheKeys` (regex or exact). Default vendor cache keys are rejected unless `captureDefaultVendorCacheKeys()`: `laravel_vapor_job_attempts:`, `illuminate:` (except `cache:flexible:created:`), `framework/schedule`, `laravel:pulse:`, `laravel:reverb:`, `nova`, `telescope:`, `livewire-checksum-failures:`. Also `Nightwatch::ignore(cb)`, `pause()`, `resume()`, `paused()`. There is no filtering for requests, commands, exceptions, logs, scheduled tasks or job attempts.

**Redaction** [code, `Concerns/RedactsRecords.php`]: callbacks mutate non-readonly record properties: `redactExceptions` (message), `redactCacheEvents` (key), `redactCommands` (command), `redactMail` (subject), `redactOutgoingRequests` (url), `redactQueries` (sql), `redactRequests` (url, ip, headers, payload, files). `Nightwatch::user(cb)` customizes id, name and username. [docs] filtering documents the pipeline observation, sampling, filtering, redaction, rate limiting (matches the code).

**Facade API** [code]: `user`, `guzzleMiddleware`, `digest`, `sample`, `dontSample`, `sampling`, `captureDefaultVendorCommands`, `defaultVendorCommands`, `ignore`, `resume`, `pause`, `paused`, `report`, `redact*`/`reject*`, `captureDefaultVendorCacheKeys`, `defaultVendorCacheKeys`, and static `handleUnrecoverableExceptionsUsing`.

## 6. Known gaps in emitted data [code unless noted]

- The counters `lazy_loads`, `files_read`, `files_written` and `hydrated_models` exist in every execution record but are never incremented anywhere in `src/` (always 0 in v1.30.2).
- Query: SQL text only, bindings never read; no row counts or plan.
- No cache values; mail has counts only (no addresses or body); notification has no recipient; queued-job has no payload or arguments.
- `mail.failed` and `notification.failed` are hard-coded false (the framework emits no failure events).
- Outgoing requests: only the Laravel Http client (or manual `guzzleMiddleware`); only fulfilled responses are recorded, connection failures and timeouts produce no record.
- Request payload only for 500s; response headers and body are never captured.
- Version-dependent fields: cache duration and failures Laravel 11.11+ (else 0), store name, queue name and Context 11.0+, mailable class 11.27+, queued-job duration 10.42+, `connection_type` 12.45.0+ (`Compatibility.php`; [docs] requirements).
- Logs only if the `nightwatch` channel is configured; unsampled executions drop logs.
- job-attempt carries no exception detail (separate exception records); sync-connection jobs emit no job-attempt.
- Vendor commands and vendor cache keys are excluded by default.
- Exceptions raised in the schedule source are skipped by `ReportableHandler` (reported via `ScheduledTaskFailed` instead).
- Exception source-code frames only for application files, at most 10.

## 7. Not verifiable

- Behaviour of the closed agent (`agent/build/agent.phar`) beyond the acknowledgement, batching and compression, and the agent-to-cloud format: not in the repository [unverified].
- No live run was made; a follow-up could bind a TCP server on `127.0.0.1:2407` and diff the bytes.
