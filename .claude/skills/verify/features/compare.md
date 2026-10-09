# Compare

`compare` splits a window at `split_at`, or takes a deploy pair (`deploy_before`, `deploy_after`), and gives each group of one type a change: `slower`, `faster`, `heavier`, `lighter`, `more_calls`, `fewer_calls`, `steady`, `new`, `gone`, `zero_baseline` or `not_evaluated`. It answers the two sides (window, records, observed span, whether each was clipped to coverage, on a pair the deploy, and the records that started before coverage on the before side of a split and on each side of a pair, counted for its own deploy), a rollup over every group and the rows ordered by absolute change. An omitted `since` is the type's coverage start and an omitted `until` is the store clock. It takes `type` or `group`, `split_at` or `deploy_before` with `deploy_after`, `by`, `since`, `until` and `limit`.

## Sub-features

- `compare-split` compares every group of the type before and after the split, with `measured_on: p50` where a side has fewer than 20 records.
- `compare-empty-side` answers `not_evaluated` / `empty_side` with no rows when a side holds no records of the type, says which side (`both` when neither deploy of a pair has any while the window holds the type) and lists the window's deploys.
- `compare-group` gives one group its one row, `new` or `gone` when it is on one side only while the type is on both.
- `compare-next` offers `occurrences` and `rank` for the first group listed, over the same window.
- `compare-pair` compares every record of `deploy_before` against every record of `deploy_after` in one window, with `straddling` `null`, no `visible-at-completion` blind spot and the note `A deploy pair cannot separate an uncommitted edit; a time split can.` on every answer, empty or not.
- `compare-refusal` refuses a call with no boundary, half a pair, `split_at` with either deploy, the same deploy twice or an empty one, a split outside the window and `limit` with `group`.

## How to get to it (user POV)

- The assistant notes `now` from any answer, the developer changes the code and uses the application, and the assistant calls `compare` with `split_at` set to that `now`, then follows the `next` calls.
- After a deploy, the assistant reads the deploys from `rank` with `group`, or from an empty side's `deploys`, and calls `compare` with `deploy_before` and `deploy_after`.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> compare '{"type":"request","split_at":"-1h"}'` answers `Nothing to report: no store has been written yet.` before any request.
- Then `app.sh get <run>` for `/`, `/products` and `/catalog`, three times each. Note the split: `app.sh mcp <run> overview '{"format":"json"}' | jq '.now'`. Then `app.sh get <run>` for `/` and `/products` three times each, and `/exports` and `/invoices/42` three times each.

- **Split.** `app.sh mcp <run> compare '{"type":"request","split_at":<now>,"format":"json"}' | jq -c '.result.groups[] | [.label, .before_records, .after_records, .change, .measured_on, .reason]'` prints `/products` 3 and 3 with a token decided by timing and `measured_on` `p50`, `/exports` and `/invoices/{invoice}` as `new`, `/catalog` as `gone`, and `/` with 2 before records as `not_evaluated` / `sample_too_small` (have 2, needed 3). `.result.rollup` counts the five groups, `one_side_only` 3 and `cut` 0, and `.coverage.straddling` is `0`. `.result.before` has `records` 8, `earlier_records` 1 and `earlier_more` `false`, and `.notes` holds `1 request record started before what the store covers for request, so it is on neither side.` Check the sides against `app.sh store <run> "select json_extract(data,'$.route_path') as route, sum(started_at >= <since> and started_at < <now>) as before_side, sum(started_at >= <now> and started_at < <until>) as after_side from records where type='request' group by route"`, with `<since>` and `<until>` from `.window`.
- **Markdown.** The same call without `format` prints the rows as one table and the store line ends `0 straddling the split`.
- **One group.** With `"group":"<the group of /exports>"` in place of `type`, `.result.groups` holds one row with `change` `new`, `.result.reason` is `null` and `.result.before.records` is `0`. With the group of `/catalog` the row is `gone`.
- **Child type.** With `"type":"query"`, `.coverage.straddling` is `null` and the markdown store line names no straddling count: only executions straddle a split.
- **Measures.** With `"by":"p95_memory"`, `/exports` is `new` at about 80 MB. With `"by":"occurrences"`, `/` 2 to 3 is `steady`, because a difference of exactly the noise floor of 1 does not move.
- **Next calls.** Run each entry of `.next` with `app.sh mcp`: `occurrences` lists the group's records and `rank` with `group` breaks it down by deploy, both over the window of the compare answer.
- **Empty side.** Note a fresh `now` (`app.sh mcp <run> rank '{"type":"request","format":"json"}' | jq '.now'`) and compare at it: `.result` has `change` `not_evaluated`, `reason` `empty_side`, `side` `after`, `groups` `[]`, `rollup` `null` and `deploys` `[]`, the summary reads `Not evaluated (empty_side): the after side holds no request records, which is not "no regression".` and `.next` is `[]`.
- **Refusal.** `app.sh mcp <run> compare '{"type":"request"}'` prints `error: missing_argument` for `split_at`, whose `accepted` names both boundaries, and exits `1`. With `"split_at":"-1d"` it prints `error: split_outside_window`, with `"split_at":"-1m","deploy_before":"v1"` `error: conflicting_arguments`, and with `group` and `limit` together `error: conflicting_arguments`.

## Driving the deploy pair

Preconditions:

- A fresh run started as `NIGHTWATCH_DEPLOY=v1 app.sh start`, then `app.sh get <run>` for `/`, `/products` and `/catalog`, three times each. Then `NIGHTWATCH_DEPLOY=v2 app.sh restart <run>` and `app.sh get <run>` for `/`, `/products`, `/exports` and `/invoices/42`, three times each. Note the clock between the two with `app.sh mcp <run> overview '{"format":"json"}' | jq '.now'`. `app.sh store <run> "select type, deploy, count(*) from records group by type, deploy"` shows `request` 9 under `v1` and 12 under `v2`.

- **Pair.** `app.sh mcp <run> compare '{"type":"request","deploy_before":"v1","deploy_after":"v2","format":"json"}'`. `.result.before` has `deploy` `v1`, `records` 8 and `earlier_records` 1 (the request that created the store); `.result.after` has `deploy` `v2`, `records` 12 and `earlier_records` 0; both span the whole window. The rows are `/products` 3 and 3 with a token decided by timing, `/exports` and `/invoices/{invoice}` `new`, `/catalog` `gone` and `/` 2 and 3 `not_evaluated` / `sample_too_small`. `.coverage.straddling` is `null` and `.notes` is `1 request record of deploy v1 started before what the store covers for request, so it is on neither side.` then the pair's sentence. Check each side against `app.sh store <run> "select deploy, count(*), (max(started_at)-min(started_at))*1000 from records where type='request' and started_at >= <since> and started_at < <until> group by deploy"`, which also gives each side's `observed_span_ms`.
- **Same question by time split.** `compare` with `"split_at":<the noted clock>,"until":<until of the pair answer>` lists the same rows with the same counts and tokens, `straddling` `0`, and the earlier-records note without a deploy instead of the pair's sentence.
- **Next calls.** `.next` lists `occurrences` for the first group under each deploy that holds it (one call for a `new` or `gone` group), then `rank` with `group`; each runs over the pair's window and the `rank` breakdown shows both deploys.
- **Empty side.** With `"deploy_before":"v2","deploy_after":"v3"` the answer is `not_evaluated` / `empty_side` on side `after`, the summary names deploy `v3`, `deploys` lists `v1` 8 and `v2` 12 by first record, and `.next` is `[]`.
- **Both deploys empty.** With `"deploy_before":"v8","deploy_after":"v9"` the answer is `not_evaluated` / `empty_side` on side `both`, the summary names both deploys, `deploys` lists `v1` 8 and `v2` 12, and `.next` is `[]`.
- **No match.** A window that holds no records of the type is `no_match`, not `empty_side`: with `"type":"job-attempt"` the answer is `no_match` with the filters `type: job-attempt, deploy_before: v1, deploy_after: v2`, and still carries the pair's sentence.
- **Refusals.** Half a pair is `error: missing_argument` for the missing half, the same deploy twice or `""` is `error: invalid_argument`.

## Gotchas

- The request that creates the store started before the store's `created_at`, which is the coverage start, so it is on neither side: send `/` three times and the before side counts 2 and states `earlier_records: 1`. An earlier `since` does not include it either; the side is then clipped to the coverage start and says `clipped: true`. Check it with `app.sh store <run> "select count(*) from records where type='request' and started_at < <since>"`.
- Real durations are not controllable, so `slower`, `faster` and `steady` on a group present on both sides depend on timing. Assert `new`, `gone`, the counts and `measured_on` instead.
- Three requests a side step the default `p95_duration` down to `p50`. Below three on either side the row is `sample_too_small`.
- The workbench sets no deploy identity unless the run is started with one, so `deploys` is `[]` on an empty-side answer of a plain run.
- Set the deploy with `NIGHTWATCH_DEPLOY`, not `FIREWATCH_DEPLOY`. Testbench's CLI registers Nightwatch's provider before Firewatch's (the `provider order` notice), so Nightwatch has read its deployment before Firewatch writes `FIREWATCH_DEPLOY` into it, and every record's deploy stays empty. A real install registers them in the right order.
