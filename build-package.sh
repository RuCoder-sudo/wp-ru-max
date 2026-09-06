#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$ROOT/packages"
rm -rf "$OUT"
mkdir -p "$OUT"

zip_extension() {
  local name="$1"
  (cd "$ROOT/extensions/$name" && zip -qr "$OUT/$name.zip" .)
}

zip_extension "com_rumax"
zip_extension "plg_system_rumax"
zip_extension "plg_content_rumax"
zip_extension "mod_rumax_widget"

(cd "$ROOT" && zip -qr "$OUT/pkg_rumax.zip" pkg_rumax.xml packages/*.zip)
cp "$OUT/pkg_rumax.zip" "$ROOT/rumax-joomla-1.0.2.zip"

echo "Created: $ROOT/rumax-joomla-1.0.2.zip"
unzip -l "$ROOT/rumax-joomla-1.0.2.zip"