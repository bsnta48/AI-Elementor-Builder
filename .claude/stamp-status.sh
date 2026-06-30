#!/usr/bin/env bash
# Stop hook: bump the "Last Updated" date in PROJECT_STATUS.md to today.
# Keeps the timestamp honest even if the model forgot to touch it.
set -euo pipefail
f="$CLAUDE_PROJECT_DIR/PROJECT_STATUS.md"
[ -f "$f" ] || exit 0
today="$(date +%F)"
# Replace any "YYYY-MM-DD — by ..." date stamp line with today's, kept as claude (auto).
perl -i -pe "s/^\d{4}-\d{2}-\d{2} \xe2\x80\x94 by .*$/${today} \xe2\x80\x94 by claude (auto-stamp)/" "$f"
exit 0
