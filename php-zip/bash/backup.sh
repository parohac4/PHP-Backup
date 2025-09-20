#!/usr/bin/env bash
# backup.sh – stáhne zálohu přes backup.php (s tokenem v query)
# Použití:
#   ./backup.sh -u URL -t TOKEN [-o backup.zip]

set -euo pipefail

URL=""
TOKEN=""
OUTFILE="backup-$(date +%F-%H%M).zip"

usage() {
  echo "Použití: $0 -u URL -t TOKEN [-o SOUBOR]"
  exit 1
}

while getopts ":u:t:o:h" opt; do
  case "$opt" in
    u) URL="$OPTARG" ;;
    t) TOKEN="$OPTARG" ;;
    o) OUTFILE="$OPTARG" ;;
    h|*) usage ;;
  esac
done

[[ -z "$URL" || -z "$TOKEN" ]] && usage

# Spuštění
curl -L --fail --show-error --compressed \
     -o "$OUTFILE" \
     "${URL}?token=${TOKEN}"

echo "Hotovo: $OUTFILE"
