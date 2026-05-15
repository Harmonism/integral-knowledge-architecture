#!/usr/bin/env bash
# Populate a SDIP bundle's corpus/ from the Harmonia Sovereignty Bundle.
#
# Usage:
#   bash scripts/populate-corpus-from-sovereignty-bundle.sh bundles/harmonism
#
# The Sovereignty Bundle ships the publishable corpus at
# https://harmonism.io/sovereignty-bundle.zip. This script downloads it,
# extracts the corpus content into the target bundle's corpus/ directory,
# and prepares the corpus for indexing.
#
# After running this script, rebuild integrity hashes:
#   sdip build $TARGET_BUNDLE_PATH

set -euo pipefail

TARGET_BUNDLE="${1:-}"
if [ -z "$TARGET_BUNDLE" ]; then
    echo "Usage: $0 <bundle-path>"
    exit 1
fi

if [ ! -d "$TARGET_BUNDLE" ]; then
    echo "Error: bundle path is not a directory: $TARGET_BUNDLE"
    exit 1
fi

SOVEREIGNTY_BUNDLE_URL="${SDIP_SOVEREIGNTY_BUNDLE_URL:-https://harmonism.io/sovereignty-bundle.zip}"
TMPDIR=$(mktemp -d -t sdip-bundle-XXXXXX)
trap "rm -rf '$TMPDIR'" EXIT

echo "→ Downloading Sovereignty Bundle from $SOVEREIGNTY_BUNDLE_URL ..."
curl -fsSL -o "$TMPDIR/sovereignty-bundle.zip" "$SOVEREIGNTY_BUNDLE_URL"

echo "→ Extracting ..."
unzip -q "$TMPDIR/sovereignty-bundle.zip" -d "$TMPDIR/extracted"

# The Sovereignty Bundle places content under harmonism-vault/. Adjust as
# needed when the bundle's internal structure changes.
SOURCE_VAULT="$TMPDIR/extracted/harmonism-vault"
if [ ! -d "$SOURCE_VAULT" ]; then
    # Fallback: assume top-level layout
    SOURCE_VAULT="$TMPDIR/extracted"
fi

echo "→ Populating $TARGET_BUNDLE/corpus/ ..."
mkdir -p "$TARGET_BUNDLE/corpus"

# Copy each top-level vault folder that maps to a SDIP corpus category.
# The Harmonism vault structure is mirrored directly.
for folder in "Philosophy" "Wheel of Harmony" "World"; do
    src="$SOURCE_VAULT/$folder"
    if [ -d "$src" ]; then
        # Convert "Wheel of Harmony" -> "wheel-of-harmony" for URL-friendly paths
        dest_name=$(echo "$folder" | tr '[:upper:] ' '[:lower:]-')
        cp -r "$src" "$TARGET_BUNDLE/corpus/$dest_name"
        echo "  ✓ $folder → corpus/$dest_name/"
    fi
done

# Copy vault-root publishable articles (Harmonism.md, MunAI.md, etc.)
shopt -s nullglob
for md in "$SOURCE_VAULT"/*.md; do
    base=$(basename "$md")
    cp "$md" "$TARGET_BUNDLE/corpus/$base"
    echo "  ✓ $base"
done
shopt -u nullglob

# Copy translations if present
if [ -d "$SOURCE_VAULT/i18n/translations" ]; then
    mkdir -p "$TARGET_BUNDLE/i18n/translations"
    cp -r "$SOURCE_VAULT/i18n/translations/"* "$TARGET_BUNDLE/i18n/translations/"
    echo "  ✓ translations"
fi

echo ""
echo "→ Done. Rebuild integrity hashes with:"
echo "    sdip build $TARGET_BUNDLE"
