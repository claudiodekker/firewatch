#!/bin/bash
set -euo pipefail

# Cloud sessions don't install enabled marketplace plugins on their own.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# The install rewrites settings.json's formatting; keep the committed file as is.
settings="$CLAUDE_PROJECT_DIR/.claude/settings.json"
saved="$(cat "$settings")"
claude plugin marketplace add mattpocock/skills >/dev/null 2>&1 || true
claude plugin install mattpocock-skills@mattpocock --scope project >/dev/null 2>&1 || true

# The private skills repo needs GitHub access; say so instead of silently running without the rulebook.
claude plugin marketplace add claudiodekker/skills >/dev/null 2>&1 \
  && claude plugin install skills@claudiodekker --scope project >/dev/null 2>&1 \
  || echo "session-start: could not install skills@claudiodekker; CODING_STANDARDS.md's general rulebook is unavailable"
printf '%s\n' "$saved" > "$settings"
