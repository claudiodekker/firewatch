#!/bin/bash
set -euo pipefail

# Cloud sessions start without enabled marketplace plugins.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# For cloud sessions started directly on this repo, which don't install the marketplaces declared in settings.json on their own.
# User scope keeps the committed settings.json untouched; a project-scope install rewrites it.
timeout 60 claude plugin marketplace add mattpocock/skills >/dev/null \
  && timeout 60 claude plugin install mattpocock-skills@mattpocock --scope user >/dev/null \
  || echo "session-start: could not install mattpocock-skills@mattpocock" >&2

# The private skills repo is cloned next to this one, and a local directory needs no GitHub credentials.
timeout 60 claude plugin marketplace add "$CLAUDE_PROJECT_DIR/../skills" >/dev/null \
  && timeout 60 claude plugin install skills@claudiodekker --scope user >/dev/null \
  || echo "session-start: could not install skills@claudiodekker" >&2
