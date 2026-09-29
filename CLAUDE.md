### Working agreements

- TDD for all code (`/tdd`): red, green, refactor. No untested logic.
- No documentation beyond `README.md`, `CHANGELOG.md`, `CONTEXT.md`, ADRs and `docs/agents/`. A PR that changes behaviour updates the README and changelog. No docblocks that restate types.
- Small PRs: one ticket, one tracer-bullet slice per PR.
- Laravel package and Pest guidance: @docs/agents/laravel.md

## Opening PRs

Before opening a PR:

1. Run /code-review in a subagent against the merge-base. Commit its fixes; only surface questions it can't resolve.
2. Re-run checks until green.
3. Use /pr to write the description from the final diff, then open the PR.

If the diff changes after the PR is open, re-run /pr to update the description.

- Answer review comments on the PR itself, in full. The thread gets at most a link.
- Open at most two PRs ahead of review. Each stacked PR is reviewed against its own base.
- Fix review feedback in the PR it was left on, even when later PRs extend that code.
- With more than one PR open, give the review and merge order in the thread.
- After a retro changes `CODING_STANDARDS.md`, re-review every open PR against it.
- Only a PR that is green and next to merge is ready for review; keep every other PR a draft. Mark a PR draft before pushing to it, and ready again once its checks pass.
