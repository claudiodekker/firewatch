# Laravel package guidance

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new top-level folders under `src/` or `tests/` (the test layers are `Scenario`, `Feature`, `Contract`, `Unit`, `Arch`, plus `Fixtures` and `Support`) or add dependencies without approval. Area subfolders (`Console/Commands`, `tests/Feature/Mcp`) need no approval.
- This is a package: run Artisan through `vendor/bin/testbench`. Pass `--no-interaction` to `make:*` commands. The workbench application is what the real sensors observe; regenerate wire fixtures with `composer fixtures`, never with a `firewatch:*` command, which runs Off and records nothing.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.

## Store

- The store is not a Laravel connection: no `DB`, PDO, Eloquent or migrations for it (ADR 0005). Global scopes and factories have nothing to apply to.

## Tests (Pest)

- Validation: one empty-arguments test for all required arguments. Test rules through the command or tool, never by asserting on `rules()`.
- Assert store state through the read path (a tool answer or the store's reader), never through the writer. `assertModelExists` and `assertDatabaseCount` don't apply: the store is not a Laravel connection.
- Real sensors first: drive the workbench. A synthetic record only where the sensors can't give exact durations, volume, many groups, deploy identities, drift shapes or other-process writers, built by the record builder from a committed wire fixture and sent through Firewatch's real ingest.
- Raw SQL inserts into the store exist only in corruption and foreign-file tests.
- Layers and tags are in `CODING_STANDARDS.md` section 11; select a layer with `--group=scenario|feature|contract|unit|arch`.
- Suite setup, only when creating or explicitly asked to tune `tests/Pest.php`: `LazilyRefreshDatabase` is for the workbench's own database, never the store.
