# Detect

`detect` runs named problem shapes over the store and lists findings worst first, each with the evidence behind it. A shape that finds nothing answers clean and says how many records it examined. `overview` shows every shape's verdict.

## Sub-features

- `detect-n-plus-one` finds the query one execution ran 3 or more times.
- `detect-failing-routes` finds the routes with a request at status 400 or above.
- `detect-failing-jobs` finds the jobs with a failed or released attempt.
- `detect-queue-latency` finds the jobs that waited 5 seconds or more, or are pending that long.
- `detect-memory` finds the executions peaking at 64 MB or more.
- `detect-exception-clusters` finds the exception groups, those with an escaped exception first.
- `detect-error-logs` finds the log lines at error or worse, grouped by message shape.
- `detect-database-bound` finds the routes that typically spend 60 percent or more of a request in queries.
- `detect-clean` answers clean with the number examined.
- `detect-threshold` overrides a shape's default.
- `detect-not-evaluated` reports a shape with no records as not evaluated, never as clean.

## How to get to it (user POV)

- The assistant calls `detect` with a `shape`, or with none to run every shape.
- The assistant calls `overview`, whose `detectors` table carries each verdict and the worst finding.

## Driving it with app.sh

Preconditions:

- A fresh run. Send `app.sh get <run> /` once. `app.sh mcp <run> overview` then lists `n-plus-one`, `failing-jobs`, `queue-latency`, `failing-tasks` and `error-logs` as `not_evaluated` with reason `no_records`, and the other four as `clean` with `examined` 1.
- Then `app.sh get <run>` for `/products`, `/purchases`, `/invoices/42` and `/exports`.

- **Pending jobs.** Before running the queue, `app.sh mcp <run> detect '{"shape":"queue-latency","threshold":1}'` answers `queue-latency: 3 findings over 3 dispatches.` Without `threshold`, the call answers `clean over 3 dispatches` until the jobs have been pending for 5 seconds, and `3 findings` after that, each with `"pending":1` in its evidence.
- **Run the queue.** `app.sh artisan <run> queue:work --stop-when-empty --no-interaction`.
- **N+1.** `app.sh mcp <run> detect '{"shape":"n-plus-one","format":"json"}' | jq '.result | {verdict, total, name: .findings[0].name, runs: .findings[0].evidence.worst_runs}'` prints `findings`, `1`, `/products: select ? as product` and `25`.
- **Failing routes.** `app.sh mcp <run> detect '{"shape":"failing-routes"}'` lists `/invoices/{invoice}`.
- **Failing jobs.** `app.sh mcp <run> detect '{"shape":"failing-jobs","format":"json"}' | jq -c '.result.findings[] | [.name, .evidence.jobs_failed, .evidence.jobs_recovered]'` prints `ChargeCard` with `1, 0` and `SyncInventory` with `0, 1`. `ShipOrder` is absent.
- **Memory.** `app.sh mcp <run> detect '{"shape":"memory"}'` lists `/exports` and no other route.
- **Exception clusters.** `app.sh mcp <run> detect '{"shape":"exception-clusters","format":"json"}' | jq -c '.result.findings[] | [.evidence.message, .count, .evidence.escaped, .evidence.fatal, .evidence.units[0].label]'` prints `The card was declined.` with `2, 2, false` and `Workbench\\App\\Jobs\\ChargeCard`, then `The warehouse timed out.` with `1, 1, false` and `Workbench\\App\\Jobs\\SyncInventory`, then `Invoice [42] could not be rendered.` with `1, 1, false` and `/invoices/{invoice}`. Its summary is `exception-clusters: 3 findings over 10 executions.`: five requests and five job attempts.
- **Error logs.** `app.sh mcp <run> detect '{"shape":"error-logs","format":"json"}' | jq -c '.result.findings[] | [.name, .count, .group, .evidence.fragment, .evidence.in_executions_with_exception]'` prints `The card was declined.` with `2, null`, the same text as its fragment and `2`, then `The warehouse timed out.` with `1`, then `Invoice [<n>] could not be rendered.` with `1`, the fragment `] could not be rendered.` and `1`. The exception handler wrote all four lines. Its summary is `error-logs: 3 findings over 4 log records.`, and `.next[1]` is `occurrences` with `type` `log`, `level` `error` and the first fragment as `matching`. With `"group"` added, the call prints `error: conflicting_arguments`.
- **Clean.** `app.sh mcp <run> detect '{"shape":"database-bound","format":"json"}' | jq '.result | {verdict, examined, total}'` prints `clean`, the number of requests sent, and `0`.
- **Threshold.** `app.sh mcp <run> detect '{"shape":"n-plus-one","threshold":26,"format":"json"}' | jq '.result.verdict'` prints `clean`, because `/products` runs its query 25 times.
- **Every shape.** `app.sh mcp <run> detect` answers one line for all nine, such as `Problem shapes: findings in n-plus-one (1), failing-routes (1), failing-jobs (2), queue-latency (3), exception-clusters (3), error-logs (3), memory (1); not evaluated (no_records): failing-tasks; clean: database-bound.`
- **Overview.** `app.sh mcp <run> overview` names the same counts in its summary, `Findings: n-plus-one (1), failing-routes (1), …`, and lists the shapes with findings first in its `detectors` table.

## Gotchas

- `threshold` for `queue-latency` is whole milliseconds. `0.001` is refused.
- `queue-latency` at its default depends on how long the jobs sat. Run `queue:work` within 5 seconds of `/purchases` for a clean verdict, or wait longer for a finding that stays after the jobs ran.
- A finding names its group by route (`/invoices/{invoice}`), not by URL.
- An `exception-clusters` finding is named by its class, so the three findings of the workbench are all `RuntimeException`. Tell them apart by `evidence.message` or `evidence.file`.
- An `error-logs` finding has no group, so `occurrences` and `rank` cannot take it by `group`. Pass `evidence.fragment` as `matching` to `occurrences`, never the shape, whose placeholders are in no message.
- No workbench route ends a process, so no finding has `"fatal":true`. `tests/Scenario/ExceptionClustersTest.php` covers a fatal error with the real sensors.
- No workbench route is database-bound, because the queries of `/products` take under a millisecond. Proving a `database-bound` finding needs a route whose queries are slow, which the workbench does not have yet.
