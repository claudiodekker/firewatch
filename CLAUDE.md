### Working agreements

- TDD for all code (`/mattpocock-skills:tdd`): red, green, refactor. No untested logic.
- No documentation beyond `README.md`, `CHANGELOG.md`, `CONTEXT.md`, ADRs and `docs/agents/`. The design set lives in the closed decision issues, `CONTEXT.md` and `docs/adr/`. A PR that changes what a user installs, configures or runs updates the README, which stays a short guide, and adds one line to the changelog. Internals belong in `CONTEXT.md` and the ADRs, not the README. No docblocks that restate types.
- Work in the `claudiodekker-skills:claudio-mode` skill.
- One PR delivers one complete, reviewable feature or fix. Never split a feature into "part 1, 2, 3" PRs; if it is too big to review, split the ticket.
- Package guardrails, store and test rules: @docs/agents/laravel.md

## Opening PRs

Before opening a PR:

1. Run /mattpocock-skills:code-review in a subagent against the merge-base. Commit its fixes; only surface questions it can't resolve.
2. Re-run checks until green.
3. Use /mattpocock-skills:pr to write the description from the final diff, then open the PR.

If the diff changes after the PR is open, re-run /mattpocock-skills:pr to update the description.

- Keep a PR a draft until its checks are green; then mark it ready.
- Answer review comments on the PR itself; fix feedback in the PR it was left on.
- After `CODING_STANDARDS.md` or the claudio-mode rulebook changes, re-review every open PR against it.

## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.
