#!/usr/bin/env bash
#
# build.sh — package the plugin into a distributable zip.
#
# Produces ai-elementor-builder.zip containing a single top-level
# `ai-elementor-builder/` folder, excluding development cruft
# (.git, .DS_Store, node_modules) so the archive installs cleanly via
# Plugins > Add New > Upload.
#
# Usage: ./build.sh

set -euo pipefail

PLUGIN_SLUG="ai-elementor-builder"
ZIP_NAME="${PLUGIN_SLUG}.zip"

# Resolve the plugin directory (this script's location) and its parent.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "${SCRIPT_DIR}")"
OUTPUT="${SCRIPT_DIR}/${ZIP_NAME}"

command -v zip >/dev/null 2>&1 || { echo "Error: 'zip' is not installed." >&2; exit 1; }

# Remove any previous archive so excludes can't leak in via an update.
rm -f "${OUTPUT}"

# Zip from the parent so paths are prefixed with the plugin slug folder.
cd "${PARENT_DIR}"
zip -r "${OUTPUT}" "${PLUGIN_SLUG}" \
	-x "${PLUGIN_SLUG}/.git/*" \
	-x "*/.git/*" \
	-x "*.DS_Store" \
	-x "${PLUGIN_SLUG}/node_modules/*" \
	-x "*/node_modules/*" \
	-x "${PLUGIN_SLUG}/${ZIP_NAME}"

echo "Built: ${OUTPUT}"
