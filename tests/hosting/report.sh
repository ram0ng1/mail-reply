#!/usr/bin/env bash
# Junta os result.json das células numa tabela 4×4 (Markdown).
#
#   tests/hosting/report.sh <pasta-com-os-json>
set -euo pipefail

DIR="$1"
hostings=(cpanel directadmin plesk vps)
deliveries=(pipe imap-cron imap-queue webhook)
labels=([0]="cPanel" [1]="DirectAdmin" [2]="Plesk" [3]="VPS")
failed=0

echo "## Matriz de hospedagem"
echo
echo '| Hospedagem | pipe | imap-cron | imap-queue | webhook |'
echo '| --- | --- | --- | --- | --- |'
for i in "${!hostings[@]}"; do
  row="| ${labels[$i]} |"
  for d in "${deliveries[@]}"; do
    f="$DIR/${hostings[$i]}-$d.json"
    if [ -f "$f" ]; then
      cell="$(jq -r 'if .ok then "✅ \(.passed)/\(.total)" else "❌ \(.passed)/\(.total)" end' "$f")"
      jq -e .ok "$f" >/dev/null || failed=$((failed + 1))
    else
      cell="⚠️ sem resultado"
      failed=$((failed + 1))
    fi
    row="$row $cell |"
  done
  echo "$row"
done

echo
for f in "$DIR"/*.json; do
  [ -f "$f" ] || continue
  jq -r 'select(.ok | not) | "- **\(.label) × \(.delivery)**: " + ([.checks[] | select(.ok | not) | "\(.case) (\(.detail))"] | join("; "))' "$f"
done

exit $(( failed > 0 ))
