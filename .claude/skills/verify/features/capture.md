# Capture

While the application runs in a capture environment, Firewatch stores every request, command and job attempt with the queries, exceptions and logs inside it, in a SQLite file the first batch creates. A process that is Off stores nothing, and outside the capture environments Firewatch steps aside.

## Sub-features

- `capture-request` stores a request with its queries.
- `capture-exception` stores the exception and log of a request that fails.
- `capture-jobs` stores each dispatch and each job attempt, in the trace of the request that dispatched them.
- `capture-command` stores a command.
- `capture-store-files` creates the store directory with a `.gitignore`, readable only by its owner.
- `capture-off` stores nothing when `FIREWATCH_ENABLED=false`.
- `capture-stepped-aside` registers no `firewatch:` command outside `FIREWATCH_ENVIRONMENTS`.
- `capture-redact` replaces a listed header's value.

## How to get to it (user POV)

- Use the application: send it requests, run its queue worker, run its commands.
- Set `FIREWATCH_ENABLED`, `FIREWATCH_ENVIRONMENTS`, `FIREWATCH_REDACT_HEADERS` or another variable from the README's configuration table in the application's environment.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> overview` says `no store has been written yet`, and `.verify/runs/<run>/store/` does not exist.

- **Request.** `app.sh get <run> /products` prints `GET /products -> 200, stored 26 records`. `app.sh store <run> "select type, count(*) from records group by type"` shows 1 `request` and 25 `query`.
- **Store files.** `ls -la .verify/runs/<run>/store/` shows a `drwx------` directory holding `firewatch.sqlite` (`-rw-------`) and a `.gitignore`.
- **Exception.** `app.sh get <run> /invoices/42` prints `-> 500, stored 3 records`. `app.sh mcp <run> occurrences '{"type":"exception"}'` lists a `RuntimeException` with the message `Invoice [42] could not be rendered.`.
- **Jobs.** `app.sh get <run> /purchases` prints `stored 7 records`: the request, 3 `queued-job` records and the 3 queries that insert them into `jobs`. `app.sh artisan <run> queue:work --stop-when-empty --no-interaction` then runs 5 attempts, and the `store` query above shows 5 `job-attempt`.
- **Command.** `app.sh artisan <run> about --only=environment`, then `app.sh mcp <run> rank '{"type":"command"}'` answers `Ranked 1 command group`.
- **Off.** Start a second run with `FIREWATCH_ENABLED=false app.sh start`. `app.sh get <run> /products` prints `-> 200, not stored within 5s`, and `ls .verify/runs/<run>/` shows no `store` directory.
- **Stepped aside.** `FIREWATCH_ENVIRONMENTS=production app.sh artisan <run> firewatch:server --list` exits `1` with `There are no commands defined in the "firewatch" namespace.` and prints `Firewatch is installed but stepped aside in environment` first.
- **Redact.** Start a run with `FIREWATCH_REDACT_HEADERS=user-agent app.sh start`, send `app.sh get <run> /`, then `app.sh mcp <run> execution '{"type":"request","format":"json"}' | jq '.result.request.headers'`. `user-agent` is `["[16 bytes redacted]"]`, the length of `firewatch-verify`, and `host` is kept as sent.

## Gotchas

- A variable given to `app.sh start` reaches only the server. Give it again to a later `artisan` or `mcp` call that must run under it.
- `doctor` fails on a run started with `FIREWATCH_ENVIRONMENTS=production`, so `start` aborts. Drive stepped aside through `artisan` on a normal run.
- The record lands after the response. Assert on the `stored …` part of the `get` line, not on a read made right after a bare `curl`.
- A request's payload is stored only when the response status is 500.
