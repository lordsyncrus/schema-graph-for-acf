#!/usr/bin/env bash
# Build script: produce un .zip pronto per l'upload da WP admin.
# Output: dist/schema-graph-for-acf-<version>.zip

set -euo pipefail

SLUG="schema-graph-for-acf"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"

# Versione letta dal plugin header
VERSION=$(grep -E "^[[:space:]]*\*[[:space:]]*Version:" "$ROOT/schema-graph.php" \
  | head -1 | sed -E 's/.*Version:[[:space:]]*([0-9A-Za-z.\-]+).*/\1/')

if [[ -z "$VERSION" ]]; then
  echo "✗ Impossibile leggere la versione da schema-graph.php" >&2
  exit 1
fi

echo "→ Build $SLUG v$VERSION"

# Lint PHP veloce (se php è disponibile)
if command -v php >/dev/null 2>&1; then
  echo "→ PHP syntax check"
  find "$ROOT" -name "*.php" \
    -not -path "*/dist/*" -not -path "*/.git/*" -not -path "*/.claude/*" \
    -print0 | xargs -0 -n1 php -l > /dev/null
fi

# Stage pulito
rm -rf "$STAGE"
mkdir -p "$STAGE"

# Files & dirs inclusi nel pacchetto
cp    "$ROOT/schema-graph.php" "$STAGE/"
cp    "$ROOT/README.md"        "$STAGE/" 2>/dev/null || true
cp -R "$ROOT/includes"         "$STAGE/"
cp -R "$ROOT/assets"           "$STAGE/"
cp -R "$ROOT/data"             "$STAGE/"

# Pulizia residui
find "$STAGE" -name ".DS_Store" -delete
find "$STAGE" -name "*.bak"     -delete

# Zip
ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="$DIST/$ZIP_NAME"
rm -f "$ZIP_PATH"

(cd "$DIST" && zip -rq "$ZIP_NAME" "$SLUG")
rm -rf "$STAGE"

SIZE=$(du -h "$ZIP_PATH" | cut -f1)
echo "✓ $ZIP_PATH ($SIZE)"
