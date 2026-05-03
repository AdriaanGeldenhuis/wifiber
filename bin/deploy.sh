#!/usr/bin/env bash
#
# Production deploy: pull the latest from origin, apply the database schema,
# and report what changed. Called by the GitHub Action in
# .github/workflows/deploy.yml after the lint job passes.
#
# Run by hand any time too:
#   cd ~/public_html && ./bin/deploy.sh

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
BEFORE="$(git rev-parse --short HEAD)"

echo "[deploy] $(date '+%F %T')"
echo "[deploy] root:    $ROOT"
echo "[deploy] branch:  $BRANCH"
echo "[deploy] before:  $BEFORE"

# Hard-sync to origin. Production should never have local diffs; if it does,
# they're discarded (use a feature branch + merge if you want them kept).
git fetch --prune origin
git reset --hard "origin/$BRANCH"

AFTER="$(git rev-parse --short HEAD)"
echo "[deploy] after:   $AFTER"

if [ "$BEFORE" = "$AFTER" ]; then
  echo "[deploy] no changes."
else
  echo ""
  echo "[deploy] commits applied:"
  git log --format='  %h  %s' "$BEFORE..$AFTER" | head -25
fi

# Apply DB schema. Idempotent — every CREATE uses IF NOT EXISTS.
if [ -f data/db.php ]; then
  echo ""
  echo "[deploy] applying schema..."
  php bin/migrate-schema.php
else
  echo "[deploy] no data/db.php — skipping schema migration"
fi

echo ""
echo "[deploy] done at $AFTER."
