# Laravel package guidance

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new top-level folders under `src/` or `tests/` (the test layers are `Scenario`, `Feature`, `Contract`, `Unit`, `Arch`, plus `Fixtures` and `Support`) or add dependencies without approval. Area subfolders (`Console/Commands`, `tests/Feature/Mcp`) need no approval.
- This is a package: run Artisan through `vendor/bin/testbench`. Pass `--no-interaction` to `make:*` commands. The workbench application is what the real sensors observe; regenerate wire fixtures with `composer fixtures`, never with a `firewatch:*` command, which runs Off and records nothing.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.

## Laravel

- The store is not a Laravel connection: no `DB`, PDO, Eloquent or migrations for it (ADR 0005). Global scopes and factories have nothing to apply to.
- Read tool and command arguments through typed accessors and validate them at the boundary. No `(int)` casts.
- One complex query vs. two simple ones: depends on selectivity; measure.

## Tests (Pest)

Coverage

- Cover every decision: each branch, validation rule, calculation and authorization, including boundaries (e.g. 19 vs 20 records, 59 vs 60 percent).
- Validation: one empty-arguments test for all required arguments. Test rules through the command or tool, never by asserting on `rules()`.
- No real-browser tests (`pest-plugin-browser`, Dusk) unless the user asks.

Assertions

- Assert store state through the read path (a tool answer or the store's reader), never through the writer. `assertModelExists` and `assertDatabaseCount` don't apply: the store is not a Laravel connection.
- Assert each fact once: no `assertOk()` before `assertSee`.
- `Http::fake()` with the exact endpoint URL, never a bare or wildcard fake.

Data

- Build telemetry inside the test that uses it; `beforeEach` is for configuration only.
- Real sensors first: drive the workbench. A synthetic record only where the sensors can't give exact durations, volume, many groups, deploy identities, drift shapes or other-process writers, built by the record builder from a committed wire fixture and sent through the real ingest event.
- Raw SQL inserts into the store exist only in corruption and foreign-file tests.

Structure

- `it()` for behaviour, `test()` for declarative facts (policy grants, enum labels, serialized shape).
- `describe()` per action when one file covers several (index/store/destroy); not for input variants.
- `use function Pest\Laravel\mock;` before calling `mock()`.
- Layers and tags are in `CODING_STANDARDS.md` section 11; select a layer with `--group=scenario|feature|contract|unit|arch`.

Suite setup: only when creating or explicitly asked to tune `tests/Pest.php`, never as part of a feature task.

- `LazilyRefreshDatabase` over `RefreshDatabase`, for the workbench's own database, never the store.
- Global `beforeEach`: `Http::preventStrayRequests()`, `Sleep::fake(syncWithCarbon: true)`, `Exceptions::fake()`.
- Fast local runs: `vendor/bin/pest --parallel`.
