# Compare

`compare` splits a window at `split_at` and gives each group of one type a change: `slower`, `faster`, `heavier`, `lighter`, `more_calls`, `fewer_calls`, `steady`, `new`, `gone`, `zero_baseline` or `not_evaluated`. It answers the two sides (window, records, observed span, whether each was clipped to coverage, and on the before side the records that started before coverage), a rollup over every group and the rows ordered by absolute change. An omitted `since` is the type's coverage start and an omitted `until` is the store clock. It takes `type` or `group`, `split_at`, `by`, `since`, `until` and `limit`.

## Sub-features

- `compare-split` compares every group of the type before and after the split, with `measured_on: p50` where a side has fewer than 20 records.
- `compare-empty-side` answers `not_evaluated` / `empty_side` with no rows when a side holds no records of the type, says which side and lists the window's deploys.
- `compare-group` gives one group its one row, `new` or `gone` when it is on one side only while the type is on both.
- `compare-next` offers `occurrences` and `rank` for the first group listed, over the same window.
- `compare-refusal` refuses a missing `split_at`, a split outside the window, `limit` with `group` and the deploy pair (`deploy_before`, `deploy_after`), which ticket #76 adds.

## How to get to it (user POV)

- The assistant notes `now` from any answer, the developer changes the code and uses the application, and the assistant calls `compare` with `split_at` set to that `now`, then follows the `next` calls.

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
- **Refusal.** `app.sh mcp <run> compare '{"type":"request"}'` prints `error: missing_argument` for `split_at` and exits `1`. With `"split_at":"-1d"` it prints `error: split_outside_window`, with `"deploy_before":"v1"` `error: conflicting_arguments`, and with `group` and `limit` together `error: conflicting_arguments`.

## Gotchas

- The request that creates the store started before the store's `created_at`, which is the coverage start, so it is on neither side: send `/` three times and the before side counts 2 and states `earlier_records: 1`. An earlier `since` does not include it either; the side is then clipped to the coverage start and says `clipped: true`. Check it with `app.sh store <run> "select count(*) from records where type='request' and started_at < <since>"`.
- Real durations are not controllable, so `slower`, `faster` and `steady` on a group present on both sides depend on timing. Assert `new`, `gone`, the counts and `measured_on` instead.
- Three requests a side step the default `p95_duration` down to `p50`. Below three on either side the row is `sample_too_small`.
- The workbench sets no deploy identity, so `deploys` is `[]` on an empty-side answer.
