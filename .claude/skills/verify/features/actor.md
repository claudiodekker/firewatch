# Actor

`actor` identifies one signed-in person from a user id, a username, a name or a part of either. It answers who the person is, lists the candidates when several people fit, or lists the people the user directory holds when nobody does. It takes no window.

## Sub-features

- `actor-identified` answers one person with the stage that decided: `id`, `username`, `name` or `contains`.
- `actor-ambiguous` lists every person the deciding stage found and chooses none.
- `actor-unknown` answers `no_match` with the people seen most recently.
- `actor-refusal` refuses a missing, empty or too long `who`, and `since`, `until` and `limit`.
- `actor-records-gone` still identifies a person whose records were cleared.

## How to get to it (user POV)

- The assistant calls `actor` with `who`, for a person the developer names or a `user_id` from another answer.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> actor '{"who":"taylor"}'` answers `Nothing to report: no store has been written yet.` before any request.
- Then `app.sh get <run>` for `/`, `/members/7`, `/members/8` and `/members/9`. `app.sh store <run> "select id, name, username from users"` prints `Taylor Otwell`, `Taylor Swift` and `Nuno Maduro` with ids `7`, `8` and `9`. The request to `/` adds no row.

- **Identified.** `app.sh mcp <run> actor '{"who":"nuno@example.com"}'` answers `Identified Nuno Maduro at the username stage.` and an `identity` entry with `"id":"9"`. `app.sh mcp <run> actor '{"who":"7","format":"json"}' | jq -c '.result.identity | [.name, .matched_by]'` prints `Taylor Otwell` and `id`. With `"who":"taylor swift"` the stage is `name`, and with `"who":"adur"` it is `contains`.
- **Ambiguous.** `app.sh mcp <run> actor '{"who":"taylor","format":"json"}' | jq -c '.result | [.matched_by, .candidate_count, (.candidates | map(.id))]'` prints `contains`, `2` and `["8","7"]`, the newest sighting first. The summary is `` `taylor` matches 2 people at the contains stage; repeat with an id. `` and `.empty` is `null`.
- **Unknown.** `app.sh mcp <run> actor '{"who":"mohamed","format":"json"}' | jq -c '[.empty.kind, .empty.population, .result.known_actor_count, (.result.known_actors | map(.id))]'` prints `no_match`, `3`, `3` and `["9","8","7"]`. With `"who":"%"` the answer is the same, because a wildcard is read as a plain character.
- **Refusal.** `app.sh mcp <run> actor '{}'` prints `error: missing_argument` and exits `1`. With `'{"who":"   "}'` it prints `error: invalid_argument`, and with `'{"who":"taylor","since":"-1d"}'` it prints `error: conflicting_arguments` and `accepted: who, format`.
- **Records gone.** `app.sh artisan <run> firewatch:clear --type=request --force`, then `app.sh mcp <run> actor '{"who":"nuno","format":"json"}' | jq -c '[.result.identity.id, .coverage.state, .coverage.records]'` prints `9`, `empty` and `0`.

## Gotchas

- `/members/{member}` signs in one of three fixed users for that request only, and any other member answers 404 without a user. No route keeps a session.
- A user's directory row is written in the same batch as the request, so the workbench cannot show a person identified from records alone (`"matched_by":"records"`), which needs the row to have aged out. `tests/Feature/Mcp/ActorTest.php` covers it.
- The workbench has three users, so the cut at ten candidates is covered only by the same test file.
- A plain `firewatch:clear` removes the users with the records. Clear by `--type` to keep the directory.
