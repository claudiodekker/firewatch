#!/bin/bash
set -euo pipefail

# Cloud sessions start without enabled marketplace plugins.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# User scope: a project-scope install is bound to this directory, and project threads start in /home/user.
# The marketplaces are declared in settings.json, but only a session started inside this repository registers them.
timeout 60 claude plugin marketplace add mattpocock/skills >/dev/null \
  && timeout 60 claude plugin install mattpocock-skills@mattpocock --scope user >/dev/null \
  || echo "session-start: could not install mattpocock-skills@mattpocock" >&2

# The private skills repo is cloned next to this one, and a local directory needs no GitHub credentials.
timeout 60 claude plugin marketplace add "$CLAUDE_PROJECT_DIR/../skills" >/dev/null \
  && timeout 60 claude plugin install skills@claudiodekker --scope user >/dev/null \
  || echo "session-start: could not install skills@claudiodekker" >&2
