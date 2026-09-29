# Laravel package guidance

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new top-level folders under `src/` (e.g. `src/Pricing`) or add dependencies without approval. Area subfolders (`Console/Commands`, `tests/Feature/Mcp`) and the framework's standard folders (`Models/Concerns`) need no approval.
- This is a package: run Artisan through `vendor/bin/testbench`. Pass `--no-interaction` to `make:*` commands.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.

## Laravel

- Local scopes over global scopes. A global scope also applies to route model binding and every relation.
- Typed request accessors: `$request->integer()`, `->boolean()`, `->string()`. No `(int)` casts.
- Build URLs with `Uri::of($base)->withQuery([...])`, not string concatenation or `http_build_query`.
- Scheduled tasks that share constraints: `Schedule::hourly()->onOneServer()->group(fn () => ...)`. Keep `onOneServer()` whenever more than one server can run the scheduler.
- One complex query vs. two simple ones: depends on selectivity; measure.

## Tests (Pest)

Coverage

- Cover every decision: each branch, validation rule, calculation and authorization, including boundaries (e.g. 8 vs 8.25 hours).
- Validation: one empty-payload test for all required fields. Test rules through the command or tool, never by asserting on `rules()`.
- No real-browser tests (`pest-plugin-browser`, Dusk) unless the user asks.

Assertions

- `assertModelExists` / `assertModelMissing` / `assertDatabaseCount`, not `->exists` or `Model::count()`.
- Assert each fact once: no `assertOk()` before `assertSee`.
- `Http::fake()` with the exact endpoint URL, never a bare or wildcard fake.

Data

- Create records inside the test that uses them; `beforeEach` is for configuration only.
- `sequence()` for several records with different attributes.
- `make()` when the test doesn't need the database. It still creates `belongsTo` parents unless you pass them in.
- `recycle($project)` to share one parent across factories instead of setting foreign keys by hand.

Structure

- `it()` for behaviour, `test()` for declarative facts (policy grants, enum labels, serialized shape).
- `describe()` per action when one file covers several (index/store/destroy); not for input variants.
- `use function Pest\Laravel\mock;` before calling `mock()`.

Suite setup: only when creating or explicitly asked to tune `tests/Pest.php`, never as part of a feature task.

- `LazilyRefreshDatabase` over `RefreshDatabase`.
- Global `beforeEach`: `Http::preventStrayRequests()`, `Sleep::fake(syncWithCarbon: true)`, `Exceptions::fake()`.
- Fast local runs: `vendor/bin/pest --parallel`.
