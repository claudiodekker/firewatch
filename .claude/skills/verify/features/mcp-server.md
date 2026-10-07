# MCP server

An assistant starts `firewatch:server` from the project root and talks to it over stdio. It initialises, lists six tools and calls them. A developer prints the same listing with `--list`. The server only reads, and prints nothing but JSON-RPC to stdout.

## Sub-features

- `server-session` answers `initialize`, `tools/list` and `tools/call` on one stdio session and exits `0` when stdin ends.
- `server-list` prints the tools without a session, as a listing or with `--json` as JSON.
- `server-refusal` answers a bad argument with `isError` and the accepted values.
- `server-clean-stdout` writes notices to stderr only.

## How to get to it (user POV)

- `php artisan firewatch:server`, started by the assistant (`claude mcp add firewatch -- php artisan firewatch:server`).
- `php artisan firewatch:server --list` and `--list --json` in a terminal.

## Driving it with app.sh

Preconditions:

- A run whose `doctor` passes. The store may be absent.

- **Session.** Write `initialize`, `notifications/initialized`, `{"jsonrpc":"2.0","id":2,"method":"tools/list"}` and a `tools/call` for `overview` with id `3` to a file, one per line. `app.sh mcp <run> --session <file> | jq -c '{id, tools: (.result.tools // [] | map(.name)), isError: .result.isError}'` prints three replies: id `1`, id `2` naming `overview`, `rank`, `occurrences`, `execution`, `trace` and `detect`, and id `3` with `isError` `false`. The helper reports `firewatch:server exited 0`.
- **One call.** `app.sh mcp <run> overview` prints an answer that starts with `## overview`.
- **Listing.** `app.sh artisan <run> firewatch:server --list` prints `Firewatch MCP server <version>: 6 tools` and one line per tool. Exit code `0`.
- **Listing as JSON.** `app.sh artisan <run> firewatch:server --list --json | jq '.tools | length'` prints `6`. Each tool carries its `inputSchema`.
- **Refusal.** `app.sh mcp <run> detect '{"shape":"bogus"}'` prints `error: invalid_argument` with `accepted: n-plus-one, database-bound, failing-routes, failing-jobs, queue-latency, failing-tasks, memory`, and exits `1`.
- **Clean stdout.** Every line of `.verify/evidence/<run>/mcp/<n>/<tool>.reply.jsonl` parses as JSON (`jq -e . <file>`), and the provider-order notice is in the `.stderr` file beside it.

## Gotchas

- `--json` without `--list` is refused.
- Start the server only with `firewatch:server`, never `mcp:start` or `mcp:inspector`.
- The server sets `app.debug` to false for its own process, so a failed call answers and the session goes on.
