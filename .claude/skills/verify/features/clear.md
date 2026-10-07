# Clear the store

`firewatch:clear` removes records from the store: all of them, one type's, or with `--drop` the whole file rebuilt from scratch. It asks before it deletes unless `--force` is given. `firewatch:doctor` exists but checks nothing yet.

## Sub-features

- `clear-confirm` asks first and deletes nothing without a yes.
- `clear-type` removes one type's records and leaves the rest.
- `clear-all` removes every record and reports the store size before and after.
- `clear-drop` rebuilds the store.
- `clear-refuse` rejects an unknown type, and `--drop` with `--type`.
- `clear-nothing` succeeds without a store.
- `doctor-stub` says it is not implemented and fails.

## How to get to it (user POV)

- `php artisan firewatch:clear`, with `--type=<type>`, `--force` or `--drop`.
- `php artisan firewatch:doctor`.

## Driving it with app.sh

Preconditions:

- A fresh run.

- **No store.** `app.sh artisan <run> firewatch:clear --force` prints `Nothing to clear.` and exits `0`. Then send `app.sh get <run> /products` and `app.sh get <run> /invoices/42`.
- **Declined.** `app.sh artisan <run> firewatch:clear --type=query < /dev/null` shows `This deletes all query records from <store path>. Continue? (yes/no) [no]`, prints `Aborted.` and exits `1`. `app.sh store <run> "select count(*) from records where type = 'query'"` still prints `25`.
- **One type.** `app.sh artisan <run> firewatch:clear --type=query --force` prints `Cleared 25 query records. Store size … -> ….` and exits `0`. `app.sh store <run> "select type, count(*) from records group by type"` shows 2 `request`, 1 `exception` and 1 `log`, and no `query`.
- **Unknown type.** `app.sh artisan <run> firewatch:clear --type=bogus --force` prints `Unknown type "bogus". Valid types: …` and exits `1`.
- **Everything.** `app.sh artisan <run> firewatch:clear --force` prints `Cleared 4 records and 0 users.` and exits `0`. `app.sh mcp <run> overview` answers `Nothing to report: the store holds no records.` and `Store: empty, 0 records; history complete from <time> (cleared)`.
- **Capture goes on.** `app.sh get <run> /products` prints `stored 26 records` again.
- **Drop.** `app.sh artisan <run> firewatch:clear --drop --force` prints `Rebuilt the store at <store path>.` and exits `0`. `app.sh store <run> "select count(*) from records"` prints `0`.
- **Drop with a type.** `app.sh artisan <run> firewatch:clear --drop --type=query --force` prints ``` `--drop` can't be combined with `--type`. ``` and exits `1`.
- **Doctor.** `app.sh artisan <run> firewatch:doctor` prints `The doctor is not implemented yet: it checked nothing.` and exits `1`.

## Gotchas

- `firewatch:doctor` is the package's command. `app.sh doctor` is this skill's check of the run. They are unrelated.
- A `firewatch:` command runs Off, so clearing never records itself.
- With `--no-interaction` and without `--force`, the command refuses before it asks. Under `app.sh artisan … < /dev/null` it reaches the prompt and aborts on end of input.
- Clearing a damaged store or one from another version needs `--drop`. Build such a store only inside `.verify/runs/<run>/store/`.
