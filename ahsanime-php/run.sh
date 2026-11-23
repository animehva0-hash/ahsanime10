#!/usr/bin/env bash
# =============================================================================
#  AHSANIME (PHP) — Run script for Linux / macOS / Termux
# =============================================================================
#  Usage:
#     bash run.sh             # start on http://localhost:8080
#     bash run.sh 9000        # start on a custom port
#
#  Requirements: PHP 8.1+  (extensions: json, openssl are bundled by default)
#  Data/images: AniList.co · Streaming: public providers · Subs: Gemini
# =============================================================================
set -e

PORT="${1:-8080}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

# --- locate PHP --------------------------------------------------------------
PHP_BIN=""
if command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
else
  echo "ERROR: 'php' not found in PATH."
  echo "  Linux (Debian/Ubuntu): sudo apt install php-cli"
  echo "  Linux (Fedora):        sudo dnf install php-cli"
  echo "  macOS (Homebrew):      brew install php"
  echo "  Termux:                pkg install php"
  exit 1
fi

# --- verify version ----------------------------------------------------------
"$PHP_BIN" -v | head -1
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' || {
  echo "ERROR: PHP 8.1+ required. You have: $("$PHP_BIN" -r 'echo PHP_VERSION;')"
  exit 1
}

# --- verify extensions -------------------------------------------------------
MISSING=""
for ext in json openssl; do
  "$PHP_BIN" -m | grep -qiE "^${ext}$" || MISSING="$MISSING $ext"
done
if [ -n "$MISSING" ]; then
  echo "ERROR: missing PHP extension(s):$MISSING"
  echo "  Debian/Ubuntu: sudo apt install php-json php-openssl"
  echo "  Termux:        pkg install php (bundled)"
  exit 1
fi

# --- ensure cache dir exists -------------------------------------------------
mkdir -p cache cache/stream

# --- .env hint (Gemini subtitle translation) --------------------------------
if [ ! -f "$DIR/.env" ] && [ ! -f "$DIR/../.env" ]; then
  echo "NOTE: no .env found. Subtitle translation (Gemini) will be disabled;"
  echo "      original English captions still work."
  echo "      Create .env with:  GOOGLE_TRANSLATE_API_KEY=your_key_here"
fi

echo ""
echo "  ============================================================"
echo "   AHSANIME is starting..."
echo "   Open in browser:  http://localhost:${PORT}"
echo "   Press Ctrl+C to stop the server."
echo "  ============================================================"
echo ""

# --- start the PHP built-in dev server (with the router) --------------------
exec "$PHP_BIN" -S "localhost:${PORT}" router.php