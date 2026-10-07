### Working agreements

- Build test-first (`/mattpocock-skills:tdd`): write the failing feature test that drives the real entry point (a request, a command, a job), then the code. Add a unit test only to pin behaviour that must never change, or to cover complex internal code that many public paths share.
- No documentation beyond `README.md`, `CHANGELOG.md`, `GLOSSARY.md`, ADRs and `docs/agents/`. The design set lives in the closed decision issues, `GLOSSARY.md` and `docs/adr/`. A PR that changes what a user installs, configures or runs updates the README, which stays a short guide, and adds one line to the changelog. Internals belong in `GLOSSARY.md` and the ADRs, not the README. No docblocks that restate types.
- Package guardrails, store and test rules: @docs/agents/laravel.md

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
