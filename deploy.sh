#!/usr/bin/env bash
# webhook.php (va boshqa server fayllarini) FTP orqali serverga yuklaydi.
# Maxfiy login/parol .deploy.env da (gitignore'langan). Parol argv'da ko'rinmaydi —
# curl -K config fayli orqali uzatiladi.
#
# Ishlatish:
#   ./deploy.sh                 # webhook.php ni yuklaydi
#   ./deploy.sh webhook.php .htaccess config.example.php
#   ./deploy.sh --apk           # SmsGateway.apk ni ham yuklaydi
#
# DIQQAT: config.php va sms.db SERVERDA qoladi — bu skript ularni TEGINMAYDI.

set -euo pipefail
cd "$(dirname "$0")"

[ -f .deploy.env ] || { echo "❌ .deploy.env topilmadi"; exit 1; }
set -a; . ./.deploy.env; set +a
: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}" "${FTP_PATH:?}"

# Yuklanadigan fayllar
if [ "${1:-}" = "--apk" ]; then FILES=(SmsGateway.apk); shift || true
elif [ $# -gt 0 ]; then FILES=("$@")
else FILES=(webhook.php); fi

# Parolni argv'dan yashirish uchun vaqtinchalik curl-config
CFG="$(mktemp)"; trap 'rm -f "$CFG"' EXIT
printf 'user = "%s:%s"\n' "$FTP_USER" "$FTP_PASS" > "$CFG"

for f in "${FILES[@]}"; do
  [ -f "$f" ] || { echo "⚠️  $f yo'q, o'tkazib yuborildi"; continue; }
  # config.php ni xato bilan ham yubormaslik
  case "$f" in config.php|sms.db|*.db|.deploy.env) echo "⛔ $f maxfiy — yuborilmadi"; continue;; esac
  echo "⬆️  $f → ${FTP_HOST}${FTP_PATH}$f"
  curl -sS --fail --max-time 120 -K "$CFG" -T "$f" "ftp://${FTP_HOST}${FTP_PATH}$f"
  echo "   ✅ yuklandi ($(wc -c < "$f") bayt)"
done
echo "✔️  Tugadi."
