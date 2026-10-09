# Trend

`trend` cuts a window into equal buckets (2 to 60, 12 by default) and states one measure per bucket: `occurrences` (the default), `max_duration`, `avg_duration`, `total_duration`, or `max_memory` for an execution type. It states a direction (`rose`, `fell` or `held`) from at least four valued buckets and the peak bucket. A missing `since` is the first selected record and a missing `until` is the last one, included; the window names them in `derived`. A bucket that starts before the type's coverage start is `partial` and left out of the direction and the peak. It takes `type` or `group`, `by`, `buckets`, `since`, `until` and `deploy`.

## Sub-features

- `trend-derived` derives the missing bounds from the selected records, names them in `.window.derived` and on the markdown window line, and adds `No records for <duration> since the last one.` once the store clock is a bucket width past a derived `until`.
- `trend-direction` answers `rose`, `fell` or `held` from four valued buckets or more, and `null` / `sample_too_small` with `have` and `needed` 4 below that.
- `trend-peak` names the earliest of the highest valued buckets, none when fewer than two are valued or all are equal, and offers `occurrences` over that bucket.
- `trend-partial` flags the buckets that start before the coverage start and counts only the records from it on; a window that ends at or before it is `outside_coverage` with no buckets.
- `trend-width-zero` gives one bucket of width 0 when every selected record started at one instant, and says so.
- `trend-refusal` refuses a percentile (pointing at `rank`), a measure the type has not, 1 or 61 buckets, a type with no groups, and `limit`, `cursor`, `user_id` or `matching`.

## How to get to it (user POV)

- The developer asks whether something is getting worse. The assistant calls `trend` with a `type` or the `group` of a `rank` row, reads the direction and the peak, then follows the `next` call to the records of the peak bucket.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> trend '{"type":"request"}'` answers `Nothing to report: no store has been written yet.` before any request.
- Then `app.sh get <run>` for `/`, `/products`, `/`, `/products` and `/catalog`, wait a few seconds, and `app.sh get <run>` for `/`, `/`, `/products`, `/exports`, `/` and `/`.

- **Derived window.** `app.sh mcp <run> trend '{"type":"request","buckets":4}'` prints the window line ending `(UTC, half-open; since derived from the first record, until derived from the last record and included)`, a bucket table with 10 requests in all, and a peak. The request that created the store started before its coverage start, so it is in no bucket. With `"format":"json"`, `.window.derived` is `["since","until"]` and `.window.description` says a derived until includes the last record. A few seconds later the notes hold `No records for <n>s since the last one.`
- **Direction.** With the traffic above, `.result.reason` is `sample_too_small`, `.result.have` is the count of buckets that hold a request (3 in the run that wrote this recipe) and `.result.needed` is 4. Real request timing decides which buckets are empty, so assert the counts, not a direction.
- **Next call.** Run `.next[0]` with `app.sh mcp`: `occurrences` over the peak bucket's edges lists as many rows as the peak's `occurrences`. A peak in the last bucket of a derived `until` ends at the store clock, so the last record is listed.
- **Partial.** Pass `since` 5 seconds before the coverage start (`.coverage.history.from`) and `until` 15 seconds after it, with `"by":"max_duration","buckets":4`. The first two buckets are `partial: true`, the empty first one has `max_duration_ms: null` and `samples: 0`, and the notes hold `2 buckets start before what the store covers for request, so they are partial and left out of the direction and peak.`
- **Outside coverage.** `app.sh artisan <run> firewatch:clear --type=request --force`, then the same window: `.result.reason` is `outside_coverage`, `.result.buckets` is `[]`, `.empty` is `null` and `.next` is `[]`.
- **Child type.** `app.sh mcp <run> trend '{"type":"query","format":"json"}'` buckets the queries, 25 per `/products` request, with empty buckets as `occurrences: 0`.
- **Refusal.** `{"type":"request","by":"p95_duration"}` prints `error: invalid_argument` with `example: rank(type: "request", by: "p95_duration")`, and `{"type":"request","buckets":61}` prints `error: invalid_argument` with `accepted: a whole number from 2 to 60`.

## Gotchas

- Real request starts are not controllable from `app.sh`, so which bucket a request lands in, and so the direction, depends on timing. The scenario test `tests/Scenario/GettingWorseTest.php` sets each start and asserts `rose`, `held` and `sample_too_small` exactly.
- A trend over one request, or over requests that all started at one instant, has width 0 and one bucket. It never states an idle time.
- Bucket edges are floats. An `until_at` passed back as `until` is exclusive, so the last record of a derived `until` drops out. The `next` call already ends at the store clock for that bucket.
