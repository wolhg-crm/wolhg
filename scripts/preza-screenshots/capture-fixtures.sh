#!/usr/bin/env bash
# Capture presentation screenshots without a live CRM DB.
# Uses Chrome headless against HTML fixtures styled with the real app.css.
# Public /homegroup can optionally be refreshed from production.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$ROOT/preza/assets/screens"
FIX="$ROOT/scripts/preza-screenshots/fixtures"
PORT="${PREZA_FIXTURE_PORT:-8097}"
mkdir -p "$OUT"

php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/preza-fixture-server.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT
sleep 0.6

for name in dashboard care person meeting regional analytics pwa knowledge; do
  google-chrome --headless=new --disable-gpu --hide-scrollbars --window-size=1440,900 \
    --screenshot="$OUT/${name}.png" "http://127.0.0.1:${PORT}/scripts/preza-screenshots/fixtures/${name}.html"
done

if [[ "${PREZA_CAPTURE_HOMEGROUP:-1}" == "1" ]]; then
  python3 "$ROOT/scripts/preza-screenshots/capture-fullpage.py" \
    --width 900 "https://wolhg.ru/homegroup" "$OUT/homegroup.png" || true
fi

if [[ "${PREZA_CAPTURE_LANDINGS:-1}" == "1" ]]; then
  python3 "$ROOT/scripts/preza-screenshots/capture-fullpage.py" \
    --width 900 "https://wolhg.ru/l/korolev" "$OUT/landing-korolev.png" || true
fi

echo "screens written to $OUT"
