# Changelog

## Unreleased

### Added

- Firewatch installs as a dev dependency and registers Nightwatch's provider and `Nightwatch` facade alias itself; Nightwatch is excluded from package discovery.
- An optional `config/firewatch.php`, published with `vendor:publish --tag=firewatch-config`, with twelve keys; every key except `budgets` can be set from a `FIREWATCH_*` environment variable.
- An invalid configuration value falls back to its default alone and is reported once per console process; web processes stay silent.
- Firewatch resolves one mode per process at registration: stepped aside outside `environments`, Off in a `firewatch:` command, when disabled or without a usable SQLite, and Active otherwise. Each mode writes only its own Nightwatch keys, so `NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN` and `NIGHTWATCH_INGEST_*` have no effect in Active and Off.
- A console process in a disallowed environment reports once that Firewatch is stepped aside. The `firewatch:server`, `firewatch:doctor` and `firewatch:clear` commands (stubs for now) and the publish tag exist only in Active and Off, and configuration issues are reported only there.
- In Active and Off, Nightwatch's ingest is swapped for one that sends nothing, after checking by reflection that its interface and property are unchanged; otherwise a console process reports it once and Nightwatch keeps its own ingest, disabled in Off and behind the dead token and address in Active.
- In Active, a listener on Nightwatch's `IngestingEvents` vetoes every batch, so Nightwatch's own ingest transmits nothing even when it could not be swapped.
- A console process in Active or Off reports once when Nightwatch's provider was registered before Firewatch's, naming the fix; in Active it also reports once when the `IngestingEvents` event is missing. Neither disables anything. Firewatch compares the installed Nightwatch version with the verified line 1.30, and later minors, majors and development builds are unverified.
