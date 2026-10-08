---
name: verify
description: Serve the Firewatch testbench workbench on its own port, store, database and queue, send it real requests and queued jobs, then call the MCP tools (overview, rank, occurrences, execution, trace, detect, actor) over a firewatch:server stdio session and run the firewatch:* commands, keeping the JSON-RPC transcripts, command output and a copy of the store as proof. Use to confirm a capture, tool, detector or command change works in a real application, to reproduce a wrong answer on the MCP surface, or before opening a PR that changes what a user runs or an assistant reads.
---

# Verify Firewatch in the workbench

Firewatch is a Laravel package, so there is no app of its own. The repo's `workbench/` is the app. `vendor/bin/testbench serve` boots a Laravel skeleton with Firewatch installed and the routes in `workbench/routes/web.php`. A user touches three things, and none of them is a page:

- **Capture.** Firewatch records what the application does (requests, commands, queued jobs) into a SQLite store.
- **The MCP server.** An assistant starts `firewatch:server` and calls its seven tools over stdio.
- **The commands.** `firewatch:server --list`, `firewatch:clear` and `firewatch:doctor`.

Every helper is a subcommand of `.claude/skills/verify/scripts/app.sh` and runs from anywhere in the checkout. Feature recipes live in [`features/README.md`](features/README.md). Read the index, then the feature file you are verifying.

## Launch

Once per checkout, run `composer install`. The helper also calls `sqlite3`, `jq`, `curl`, `lsof` and `pgrep`, which macOS ships.

```shell
.claude/skills/verify/scripts/app.sh start          # or: app.sh start 8400 to pick the first port tried
# run=20261007-120200-5512 url=http://127.0.0.1:8300 evidence=/…/.verify/evidence/20261007-120200-5512
```

`start` creates `.verify/runs/<run>/`, migrates the run's own application database (`app.sqlite`, which holds the `jobs` table), and serves the workbench on the first free port from 8300. It then runs `doctor` and prints `run=… url=…` only when every check passes. When anything fails, including the port being taken in the meantime, `start` stops what it started, prints `start failed, see <evidence directory>` and exits `1`. Pass the printed run id to every other subcommand.

Every process `app.sh` starts for a run gets `FIREWATCH_DATABASE=.verify/runs/<run>/store/firewatch.sqlite`, `DB_DATABASE=.verify/runs/<run>/app.sqlite`, `QUEUE_CONNECTION=database`, `CACHE_STORE=array` and `MAIL_MAILER=array`. A fresh run has no store. The first captured batch creates it.

Every other variable comes from the shell that calls `app.sh`. To run a process with other Firewatch configuration, set the variable in front of the subcommand, for example `FIREWATCH_ENABLED=false app.sh start` or `FIREWATCH_REDACT_HEADERS=user-agent app.sh start`. The server keeps what `start` was given. Later `artisan` and `mcp` calls are separate processes and need the variable again. Before a run that must use the defaults, check that `env | grep -E '^(FIREWATCH|NIGHTWATCH|DB|QUEUE)_'` prints nothing.

## Doctor

Run it first whenever anything looks off. It records nothing:

```shell
.claude/skills/verify/scripts/app.sh doctor <run>
# ok   server process 3470 is running
# ok   port 8300 is served by our php -S (3519)
# ok   firewatch:server dev-master lists: overview rank occurrences execution trace detect actor
# ok   the skeleton holds testbench.yaml, so the workbench routes are loaded
# ok   the store at /…/.verify/runs/<run>/store/firewatch.sqlite holds 71 records
```

It checks that the `testbench serve` process the run started is running, and that a `php -S` child of that process holds the port, not someone else's server. It checks that `firewatch:server --list` boots and lists the tools, that the skeleton still holds the `testbench.yaml` the routes load through, and that the store is absent or readable. Any `FAIL` line means you should not drive that run. Stop it and start a fresh one.

## Drive

Produce telemetry by exercising the workbench the way a user's application would be exercised. Each route exists to produce one problem shape:

| Request | What it does | What Firewatch should hold afterwards |
|---|---|---|
| `GET /` | answers `ok` | one request |
| `GET /products` | runs `select ? as product` 25 times | a request with 25 queries, an `n-plus-one` finding |
| `GET /purchases` | dispatches `ShipOrder`, `ChargeCard` and `SyncInventory` to the `database` queue | a request, three `queued-job` records and the three `insert into "jobs"` queries, in one trace |
| `GET /invoices/42` | throws a `RuntimeException`, status 500 | a request, an exception and a log, a `failing-routes` finding |
| `GET /exports` | builds an 80 MB string | a request peaking above 64 MB, a `memory` finding |
| `GET /quotes` | calls a faked dependency that answers 503 | a request and an outgoing request, a `failing-http` finding |
| `GET /catalog` | reads the key `catalog` through `Cache::remember()` | a request, a miss and a write, and from the third request a `cache` finding |
| `GET /members/7` | signs in Taylor Otwell for the request; `8` is Taylor Swift and `9` is Nuno Maduro | a request that carries the user, and that user in the user directory |

```shell
.claude/skills/verify/scripts/app.sh get <run> /products
# GET /products -> 200, stored 26 records
```

`get` sends the request with `curl` as user agent `firewatch-verify`, then waits up to 5 seconds for the store's record count to grow and settle, because Nightwatch flushes after the response is sent. `not stored within 5s` is the expected result when the run captures nothing. When the server does not answer, `get` prints `-> no response` and exits `1`. Send one `get` at a time per run, because the count it reports is the store's growth while it waited.

Run the queued jobs and any other command through the run:

```shell
.claude/skills/verify/scripts/app.sh artisan <run> queue:work --stop-when-empty --no-interaction
.claude/skills/verify/scripts/app.sh artisan <run> about --only=environment     # a command Firewatch records
.claude/skills/verify/scripts/app.sh artisan <run> firewatch:clear --type=query --force
```

`ShipOrder` is processed on its first attempt. `ChargeCard` is released once and then fails. `SyncInventory` is released once and then processed.

Call a tool the way an assistant does. `mcp` opens one `firewatch:server` stdio session, sends `initialize`, `notifications/initialized` and one `tools/call`, and prints the answer's text:

```shell
.claude/skills/verify/scripts/app.sh mcp <run> overview
.claude/skills/verify/scripts/app.sh mcp <run> detect '{"shape":"n-plus-one"}'
.claude/skills/verify/scripts/app.sh mcp <run> rank '{"type":"request","by":"p95_duration","format":"json"}' | jq '.result'
```

The third argument is the tool's `arguments` object. `app.sh artisan <run> firewatch:server --list --json | jq '.tools[] | {name, inputSchema}'` prints every tool's argument schema. `artisan` keeps the command's stdout and stderr apart, so its stdout can be piped. With `"format":"json"` the text is the answer envelope, whose `result` key holds the tool's own data. `mcp` exits `1` when the tool refuses the call (`isError`), and still prints the refusal.

For a session one call can't express (several calls on one connection, `tools/list`, malformed input), write the JSON-RPC lines to a file and send them as one session. It prints the reply lines and exits with the server's exit code:

```shell
.claude/skills/verify/scripts/app.sh mcp <run> --session /path/to/session.jsonl | jq -c '{id, result: (.result | keys)}'
```

Copy the `initialize` and `notifications/initialized` lines from any saved `mcp/<n>/<tool>.request.jsonl`.

To add a route, a job or a command that a recipe needs, put it in `workbench/` and say so in the PR. The Pest suite boots the same workbench, so keep its URIs clear of the ones the tests register themselves (`grep -rhoE "Route::[a-z]+\('[^']*'" tests`).

## Evidence

Everything lands in `.verify/evidence/<run>/`, which git ignores:

- `requests.log` has one line per `get`: the path, the status and how many records were stored.
- `artisan.log` has every `artisan` call: the command, its output and its exit code.
- `mcp/<n>/<tool>.request.jsonl`, `.reply.jsonl` and `.stderr` hold each `mcp` call's raw JSON-RPC session, numbered in call order. A `--session` call is saved as `session.*`.
- `server.log`, `migrate.log` and `doctor.log` come from `start`.
- `stop` adds the run's `app.sqlite` and a copy of the store directory, `store/`.

Read the store beside what a tool says. The session can't write:

```shell
.claude/skills/verify/scripts/app.sh store <run> "select type, count(*) from records group by type"
```

Proof standards:

- Drive the real user path. Produce records with requests, queued jobs and commands, and read them back through `mcp`. Never insert into the store, and never call a Firewatch class in place of a tool.
- Capture the action and the resulting state: the `get` or `artisan` line that produced the records, and the tool answer that reports them.
- Verify the side effect beside the answer: the `store` counts, the files in `.verify/runs/<run>/store/`, the command's exit code.
- Prove absence too. A clean verdict must say how many records it examined, and an Off run must leave no store behind.
- Quote the numbers a change is about (`examined`, `total`, a finding's `evidence`), not only the summary line.

## Cleanup

```shell
.claude/skills/verify/scripts/app.sh list           # runs in this checkout and whether they are up
.claude/skills/verify/scripts/app.sh stop <run>
```

`stop` kills only the server it started. That is the pid in `.verify/runs/<run>/server.pid`, only while its command line is still `testbench serve` on the run's port, and the `php -S` child of that pid. It copies the run's `app.sqlite` and store into the evidence directory, then deletes `.verify/runs/<run>`. When the server survives, or a copy fails, `stop` exits `1` and leaves the run directory in place. Never kill `php` or `testbench` by name, because the user may be running `composer serve`. Evidence stays in `.verify/evidence/<run>/`. Delete it only when the user asks. Stop a run after a failed recipe too, before you start the next one.

## Isolation

Runs in one checkout can run side by side. Each has its own port, store, application database and queue, and nothing is cached or mailed outside its processes. They share the testbench skeleton under `vendor/orchestra/testbench-core/laravel/`. That covers its `storage/` directory with `logs/laravel.log`, and the `.env` and `bootstrap/cache/testbench.yaml` that every testbench process copies in and a killed one leaves behind. Every server in the checkout reads its routes through that `testbench.yaml`, so `stop` uses `SIGKILL`, which gives testbench no chance to delete the two files, and leaves them in place. The Pest suite's `process` group leaves the same files behind. When another testbench process does delete them, every route of a live run answers 404 and `doctor` fails on its `testbench.yaml` line. A separate git worktree has its own `vendor/` and so shares nothing.

Never point a run at `storage/firewatch/firewatch.sqlite` in the skeleton, which is the default store and the one `composer serve` writes.

## Gotchas

- Every testbench process prints `Nightwatch's provider was registered before Firewatch's…` to stderr. Testbench's CLI discovers Nightwatch beside Firewatch, which `composer.json` keeps a real install from doing. It is expected here, and the store notes it once as `structure` drift with detail `provider order`. Any other notice is a finding.
- A `firewatch:*` command always runs Off. It never records itself, so `artisan <run> firewatch:clear` adds nothing to the store.
- `queue:work` leaves no command record, because Nightwatch records its job attempts in place of the command. The workbench has no `inspire` command. Use `about` when a recipe needs a recorded command.
- Percentiles need 3 samples. Send a request three times before asserting `p50_ms` or `p95_ms`, or the answer withholds them with `sample_too_small`.
