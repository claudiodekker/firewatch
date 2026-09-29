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
printf '%s\n' "$saved" > "$settings"
