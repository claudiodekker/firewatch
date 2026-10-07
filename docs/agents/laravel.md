# Laravel package guidance

## Guardrails

- Don't create new top-level folders under `src/` or `tests/` (the test layers are `Scenario`, `Feature`, `Contract`, `Unit`, `Arch`, plus `Fixtures` and `Support`) or add dependencies without approval. Area subfolders (`Console/Commands`, `tests/Feature/Mcp`) need no approval.
- This is a package: run Artisan through `vendor/bin/testbench`. The workbench application is what the real sensors observe; regenerate wire fixtures with `composer fixtures`, never with a `firewatch:*` command, which runs Off and records nothing.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.

## Store

- The store is not a Laravel connection: see `CODING_STANDARDS.md` sections 2 and 10.

## Tests (Pest)

- Layers, tags, real sensors, synthetic records and raw inserts are in `CODING_STANDARDS.md` section 11; select a layer with `--group=scenario|feature|contract|unit|arch`.
- Assert store state through the read path (a tool answer or the store's reader), never through the writer.
- When creating or explicitly asked to tune `tests/Pest.php`, apply `LazilyRefreshDatabase` to the workbench's own database only, never the store.
