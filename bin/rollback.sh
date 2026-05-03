#!/usr/bin/env bash
#
# Roll the production tree back to a previous commit and re-apply the
# schema. Defaults to one commit back; pass any rev to go further or
# straight to a specific hash.
#
# Examples:
#   ./bin/rollback.sh           # back one commit
#   ./bin/rollback.sh HEAD~3    # back three
#   ./bin/rollback.sh b9ece41   # to that exact commit
#   ./bin/rollback.sh -y HEAD~1 # skip the confirmation prompt

set -euo pipefail

cd "$(dirname "$0")/.."

# Optional -y / --yes to skip prompt (useful from cron / scripts).
ASSUME_YES=0
if [ "${1:-}" = "-y" ] || [ "${1:-}" = "--yes" ]; then
  ASSUME_YES=1
  shift
fi

TARGET="${1:-HEAD~1}"

CURRENT_HASH="$(git rev-parse --short HEAD)"
TARGET_HASH="$(git rev-parse --short "$TARGET" 2>/dev/null || true)"
if [ -z "$TARGET_HASH" ]; then
  echo "rollback: '$TARGET' is not a valid commit" >&2
  exit 1
fi

if [ "$CURRENT_HASH" = "$TARGET_HASH" ]; then
  echo "rollback: already at $TARGET_HASH"
  exit 0
fi

echo "Production tree:"
git log --format='  %h  %ci  %s' -10 | sed -n '1,10p'
echo ""
echo "rollback: from $CURRENT_HASH  ->  $TARGET_HASH"
echo "  ($(git log -1 --format='%s' $TARGET_HASH))"
echo ""

if [ "$ASSUME_YES" -ne 1 ]; then
  read -r -p "Roll back? (y/N) " ans
  case "$ans" in
    y|Y|yes|YES) ;;
    *) echo "rollback: aborted"; exit 1 ;;
  esac
fi

git reset --hard "$TARGET_HASH"
echo "rollback: now at $(git rev-parse --short HEAD)"

# Re-apply the schema for the rolled-back code (idempotent — drops nothing).
# If the rollback removed a table, you'll need to drop it by hand; this
# script never destroys data.
if [ -f data/db.php ]; then
  echo "rollback: re-applying schema..."
  php bin/migrate-schema.php
fi

echo "rollback: done."
