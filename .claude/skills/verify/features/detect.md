# Detect

`detect` runs named problem shapes over the store and lists findings worst first, each with the evidence behind it. A shape that finds nothing answers clean and says how many records it examined. `overview` shows every shape's verdict.

## Sub-features

- `detect-n-plus-one` finds the query one execution ran 3 or more times.
- `detect-failing-routes` finds the routes with a request at status 400 or above.
- `detect-failing-jobs` finds the jobs with a failed or released attempt.
- `detect-queue-latency` finds the jobs that waited 5 seconds or more, or are pending that long.
- `detect-memory` finds the executions peaking at 64 MB or more.
- `detect-database-bound` finds the routes that typically spend 60 percent or more of a request in queries.
- `detect-clean` answers clean with the number examined.
- `detect-threshold` overrides a shape's default.
- `detect-not-evaluated` reports a shape with no records as not evaluated, never as clean.

## How to get to it (user POV)

- The assistant calls `detect` with a `shape`, or with none to run every shape.
- The assistant calls `overview`, whose `detectors` table carries each verdict and the worst finding.

## Driving it with app.sh

Preconditions:

- A fresh run. Send `app.sh get <run> /` once. `app.sh mcp <run> overview` then lists `n-plus-one`, `failing-jobs` and `queue-latency` as `not_evaluated` with reason `no_records`, and the other three as `clean` with `examined` 1.
- Then `app.sh get <run>` for `/products`, `/purchases`, `/invoices/42` and `/exports`.

- **Pending jobs.** Before running the queue, `app.sh mcp <run> detect '{"shape":"queue-latency","threshold":1}'` answers `queue-latency: 3 findings over 3 dispatches.` Without `threshold`, the call answers `clean over 3 dispatches` until the jobs have been pending for 5 seconds, and `3 findings` after that, each with `"pending":1` in its evidence.
- **Run the queue.** `app.sh artisan <run> queue:work --stop-when-empty --no-interaction`.
- **N+1.** `app.sh mcp <run> detect '{"shape":"n-plus-one","format":"json"}' | jq '.result | {verdict, total, name: .findings[0].name, runs: .findings[0].evidence.worst_runs}'` prints `findings`, `1`, `/products: select ? as product` and `25`.
- **Failing routes.** `app.sh mcp <run> detect '{"shape":"failing-routes"}'` lists `/invoices/{invoice}`.
- **Failing jobs.** `app.sh mcp <run> detect '{"shape":"failing-jobs","format":"json"}' | jq -c '.result.findings[] | [.name, .evidence.jobs_failed, .evidence.jobs_recovered]'` prints `ChargeCard` with `1, 0` and `SyncInventory` with `0, 1`. `ShipOrder` is absent.
- **Memory.** `app.sh mcp <run> detect '{"shape":"memory"}'` lists `/exports` and no other route.
- **Clean.** `app.sh mcp <run> detect '{"shape":"database-bound","format":"json"}' | jq '.result | {verdict, examined, total}'` prints `clean`, the number of requests sent, and `0`.
- **Threshold.** `app.sh mcp <run> detect '{"shape":"n-plus-one","threshold":26,"format":"json"}' | jq '.result.verdict'` prints `clean`, because `/products` runs its query 25 times.
- **Every shape.** `app.sh mcp <run> detect` answers one line for all six, such as `Problem shapes: n-plus-one 1 findings; failing-routes 1 findings; failing-jobs 2 findings; queue-latency 3 findings; memory 1 findings; database-bound clean.`
- **Overview.** `app.sh mcp <run> overview` names the same counts in its summary, `Findings: n-plus-one (1), failing-routes (1), …`, and lists the shapes with findings first in its `detectors` table.

## Gotchas

- `threshold` for `queue-latency` is whole milliseconds. `0.001` is refused.
- `queue-latency` at its default depends on how long the jobs sat. Run `queue:work` within 5 seconds of `/purchases` for a clean verdict, or wait longer for a finding that stays after the jobs ran.
- A finding names its group by route (`/invoices/{invoice}`), not by URL.
- No workbench route is database-bound, because the queries of `/products` take under a millisecond. Proving a `database-bound` finding needs a route whose queries are slow, which the workbench does not have yet.
