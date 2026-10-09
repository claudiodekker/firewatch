# Firewatch verification map

This directory is the maintained source for verifying what a Firewatch user touches: capture, the MCP tools and the `firewatch:*` commands. Read this index before driving the workbench, then use the matching feature file as the recipe. The subcommands, routes and evidence files it names are defined in [`../SKILL.md`](../SKILL.md). `app.sh` below is `.claude/skills/verify/scripts/app.sh`.

## Baseline preconditions

- Start a run with `app.sh start`. The run has its own port, Firewatch store, application database and queue, and a fresh run has no store.
- `app.sh doctor <run>` passes with no `FAIL` line.
- Never drive a server this verification run did not start, including the user's own `composer serve`, and never read or clear a store outside `.verify/runs/<run>/`.

## Driving conventions

- Produce records with `app.sh get <run> <path>`, and with `app.sh artisan <run> queue:work --stop-when-empty --no-interaction` for the jobs `/purchases` dispatches.
- Read records with `app.sh mcp <run> <tool> '<arguments>'`. Add `"format":"json"` and pipe to `jq` when you assert on a value.
- Run commands with `app.sh artisan <run> <command>` so they hit the run's store.
- Start each recipe from a fresh run, or from a run whose state you have read back with `app.sh mcp <run> overview`. Counts carry over within a run.

## Proof and skip reporting

- Capture the action and the result: the `get` or `artisan` line that produced the records, then the tool answer that reports them.
- Read the side effect back with `app.sh store <run> "<query>"`, or by listing `.verify/runs/<run>/store/`.
- Command proof includes the command, its output and its exit code, which `artisan.log` keeps.
- Name the feature file and sub-feature id with every artifact in `.verify/evidence/<run>/`.
- Report a path you could not reach with the command you tried and the precondition that was missing. Never report one entry point as verified through another. A tool answer in markdown does not prove its JSON, and `firewatch:server --list` does not prove a session.

## Feature entry contract

Each feature file starts with an H1 title and one paragraph describing the user-visible behaviour. It then has exactly four H2 sections, in this order.

1. `Sub-features` lists short IDs, one line per behaviour.
2. `How to get to it (user POV)` lists every user entry point.
3. `Driving it with app.sh` starts with `Preconditions:`, then pairs each user action with the exact call and the observable result.
4. `Gotchas` lists traps that can waste or invalidate a run.

## Features

- [Capture](./capture.md) covers what a request, a queued job and a command leave in the store, the Off and stepped-aside modes, and header redaction.
- [MCP server](./mcp-server.md) covers `firewatch:server` over stdio, its tool listing and a refused call.
- [Explore tools](./explore-tools.md) covers `overview`, `rank`, `occurrences`, `execution` and `trace`, in markdown and JSON.
- [Detect](./detect.md) covers the eleven problem shapes, a clean verdict, a threshold override and the verdicts in `overview`.
- [Actor](./actor.md) covers `actor`: one person identified by id, username, name or a part of either, the candidates when several fit, and the known actors when nobody does.
- [Compare](./compare.md) covers `compare`: the groups of one type before and after a `split_at` or between the deploys of a pair, an empty side, and its refusals.
- [Trend](./trend.md) covers `trend`: a measure over equal buckets with derived bounds, a direction, a peak, partial buckets and its refusals.
- [Query](./query.md) covers `query`: the assistant's own read-only SQL, its rows and the record types it read, the limit, a refusal and the deadline.
- [Clear the store](./clear.md) covers `firewatch:clear` with its confirmation, `--type`, `--force` and `--drop`, and the `firewatch:doctor` stub.
