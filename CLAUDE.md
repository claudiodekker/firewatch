### Working agreements

- Build test-first (`/mattpocock-skills:tdd`): write the failing test that drives the real entry point (an Artisan command, an MCP tool, or Firewatch's ingest fed by the real sensors), then the code. The default layer is the scenario test (`tests/Scenario`); a feature test (`tests/Feature`) drives one command, ingest or retention pass end to end (`CODING_STANDARDS.md` section 11). Add a unit test only for what a feature test can't reach (arithmetic tables, grammars, polling, error classification), and delete one that a feature or scenario test already covers.
- No documentation beyond `README.md`, `CHANGELOG.md`, `GLOSSARY.md`, ADRs and `docs/agents/`. The design set lives in the closed decision issues, `GLOSSARY.md` and `docs/adr/`. A PR that changes what a user installs, configures or runs updates the README, which stays a short guide, and adds one line to the changelog. Internals belong in `GLOSSARY.md` and the ADRs, not the README. No docblocks that restate types.
- Package guardrails (folders, testbench, wire fixtures, Pint) and test-layer selection: @docs/agents/laravel.md

## Opening PRs

- Re-run checks until green before opening a PR.
- Answer review comments on the PR itself; fix feedback in the PR it was left on.
- After `CODING_STANDARDS.md` changes, re-review every open PR against it.

## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `GLOSSARY.md` + `docs/adr/`. See `docs/agents/domain.md`.
