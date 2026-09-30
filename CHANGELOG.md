# Changelog

## Unreleased

### Added

- Firewatch installs as a dev dependency and registers Nightwatch's provider and `Nightwatch` facade alias itself; Nightwatch is excluded from package discovery.
- An optional `config/firewatch.php`, published with `vendor:publish --tag=firewatch-config`, with twelve keys; every key except `budgets` can be set from a `FIREWATCH_*` environment variable.
- An invalid configuration value falls back to its default alone and is reported once per console process; web processes stay silent.
