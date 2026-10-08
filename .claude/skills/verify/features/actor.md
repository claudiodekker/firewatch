# Actor

`actor` identifies one signed-in person from a user id, a username, a name or a part of either, then attributes the executions of the window to them. It answers who the person is with the attribution counts, the activity per record type and the person's executions, lists the candidates when several people fit, or lists the people the user directory holds when nobody does. It takes `since`, `until` and `limit`.

## Sub-features

- `actor-identified` answers one person with the stage that decided: `id`, `username`, `name` or `contains`.
- `actor-attributed` ties executions to the person by `direct` (its own user), `dispatch` (a job attempt with no user whose dispatch names them) or `inside` (a command or task with a child that carries them), and counts what it cannot attribute.
- `actor-ambiguous` lists every person the deciding stage found and chooses none.
- `actor-unknown` answers `no_match` with the people seen most recently.
- `actor-nothing-attributed` answers `no_match` with the identity and the counts when the window holds executions and none is the person's, and `window_empty` with an empty `result` when it holds none.
- `actor-refusal` refuses a missing, empty or too long `who`, a `limit` outside 1 to 100 and an unreadable `since` or `until`.

## How to get to it (user POV)

- The assistant calls `actor` with `who`, for a person the developer names or a `user_id` from another answer, and follows the `next` calls to the newest execution, its group and the person's own records.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> actor '{"who":"taylor"}'` answers `Nothing to report: no store has been written yet.` before any request.
- Then `app.sh get <run>` for `/`, `/members/8`, `/members/7` and `/members/7/orders`, then `app.sh artisan <run> queue:work --stop-when-empty --no-interaction`, `app.sh artisan <run> members:audit 7` and `app.sh artisan <run> about --only=environment`. `app.sh store <run> "select id, name from users"` prints `Taylor Otwell` and `Taylor Swift` with ids `7` and `8`.

- **Identified.** `app.sh mcp <run> actor '{"who":"swift@example.com","format":"json"}' | jq -c '.result.identity | [.name, .matched_by]'` prints `Taylor Swift` and `username`. With `"who":"7"` the stage is `id`, with `"who":"taylor otwell"` it is `name`, and with `"who":"otw"` it is `contains`.
- **Attributed.** `app.sh mcp <run> actor '{"who":"7","format":"json"}' | jq -c '.result.attribution | [.requests, .job_attempts, .commands]'` prints requests `total 4, this_actor 2, other_actors 1, guest 1`, job attempts `total 1, this_actor 1` and commands `total 2, this_actor 1, unattributable 1`. `jq -c '.result.executions | map(.link)'` holds `direct` twice, `dispatch` once and `inside` once, newest first. The summary reads `Taylor Otwell: 4 of 7 executions in the window attributed (2 direct, 1 dispatch, 1 inside); 2 cannot be attributed.` Check the counts against `app.sh store <run> "select type, user_id, job_id from records where type in ('request','job-attempt','command','queued-job')"`: the attempt's `user_id` is empty and the `queued-job` record with its `job_id` carries `7`.
- **Next calls.** Run each entry of `.next` with `app.sh mcp`: `execution` opens the newest listed execution, `occurrences` with `group` lists its group, and `occurrences` with `user_id` lists only records that carry `7` and notes `Filtered by recorded user only; use `actor` for dispatch and inside links.`
- **Nothing attributed.** With `"who":"8"` and `since` set to the attempt's `started_at`, `.empty.kind` is `no_match`, the summary reads `Nothing in this window is attributed to Taylor Swift.`, `.result.identity.id` is `8` and the `attribution` block still counts the attempt under `job_attempts.other_actors`. With a `since` after the last record, `.empty.kind` is `window_empty`, the summary reads `Nothing to report: no records fall in the window.`, and `.result` is `{}` and `.next` is `[]`, as in every tool's empty answer.
- **Ambiguous.** `app.sh mcp <run> actor '{"who":"taylor","format":"json"}' | jq -c '.result | [.matched_by, .candidate_count, (.candidates | map(.id))]'` prints `contains`, `2` and the two ids, the newest sighting first, and `.empty` is `null`.
- **Unknown.** `app.sh mcp <run> actor '{"who":"mohamed","format":"json"}' | jq -c '[.empty.kind, .result.known_actor_count]'` prints `no_match` and `2`. With `"who":"%"` the answer is the same, because a wildcard is read as a plain character.
- **Refusal.** `app.sh mcp <run> actor '{}'` prints `error: missing_argument` and exits `1`. With `'{"who":"taylor","limit":0}'` it prints `error: invalid_argument`, and with `'{"who":"taylor","since":"soon"}'` it prints `error: unreadable_time`.

## Gotchas

- `/members/{member}` signs in one of three fixed users for that request only, and any other member answers 404 without a user. No route keeps a session.
- `/members/{member}/orders` dispatches before it signs the member in, so the job's Context carries no user. A job dispatched after sign-in carries the user on its attempt too, which is `direct`, not `dispatch`.
- `members:audit {member}` signs the member in inside the command, so its query carries the member and the command is `inside`. The command record itself never carries a user.
- A user's directory row is written in the same batch as the request, so the workbench cannot show a person identified from records alone (`"matched_by":"records"`), which needs the row to have aged out. `tests/Feature/Mcp/ActorTest.php` covers it.
- `firewatch:clear --type=queued-job --force` removes the dispatch, and the attempt then counts under `job_attempts.no_actor` with its note. A plain `firewatch:clear` removes the users with the records. Clear by `--type` to keep the directory.
