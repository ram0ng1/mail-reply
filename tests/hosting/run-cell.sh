#!/usr/bin/env bash
# Uma célula da matriz de hospedagem, de ponta a ponta:
#
#   tests/hosting/run-cell.sh <cpanel|directadmin|plesk|vps> <pipe|imap-cron|imap-queue|webhook> [results-dir]
#
# 1. monta o fórum com o php.ini do painel (setup-forum.sh);
# 2. grava as configurações, cria contas e discussão, cunha os aliases e
#    escreve as mensagens de Gmail, Outlook, Apple Mail e Thunderbird mais os
#    casos negativos (scenario.php setup);
# 3. entrega cada mensagem pelo caminho da célula: SMTP no Exim/Postfix com
#    pipe para o script gerado pelo admin, SMTP até a caixa do Dovecot lida
#    pelo cron (schedule:run) ou pelo queue:work, ou POST dos provedores;
# 4. confere banco, caixa IMAP e o Reply-To de saída (scenario.php assert).
set -euo pipefail
if [ -n "${HOSTING_DEBUG:-}" ]; then set -x; fi

HOSTING="$1"
DELIVERY="$2"
HERE="$(cd "$(dirname "$0")" && pwd)"
RESULTS="${3:-$HERE/results}"
export FORUM_DIR="${FORUM_DIR:-/srv/forum}"
source "$HERE/profiles/$HOSTING.env"
source "$HERE/lib.sh"

"$HERE/setup-forum.sh" "$HOSTING" "$DELIVERY"

# O cenário roda com o PHP do painel; com open_basedir ele precisa estar
# dentro do site.
rm -rf "$FORUM_DIR/.hosting"
mkdir -p "$FORUM_DIR/.hosting"
cp -r "$HERE/scenario.php" "$HERE/webhook.php" "$HERE/profiles" "$HERE/fixtures" "$FORUM_DIR/.hosting/"
OUT="$FORUM_DIR/.hosting/out"
mkdir -p "$OUT"
cd "$FORUM_DIR"
fphp .hosting/scenario.php setup "$HOSTING" "$DELIVERY" "$OUT"

INBOUND_URL=http://127.0.0.1:8080/api/mail-reply/inbound
SECRET="$(jq -r .webhook_secret "$OUT/manifest.json")"
EXPECTED_ROWS="$(jq '[.cases[] | select(.expect != "duplicate")] | length' "$OUT/manifest.json")"
extra=()

add_check() { # nome ok(0|1) detalhe
  extra+=("$(jq -cn --arg c "$1" --argjson ok "$2" --arg d "$3" '{case:$c, ok:($ok==1), detail:$d}')")
}

wait_rows() { # quantas linhas no log de entrada, até N segundos
  local want="$1" secs="$2" n=0
  for _ in $(seq 1 "$secs"); do
    n="$(fphp .hosting/scenario.php count)"
    [ "$n" -ge "$want" ] && break
    sleep 1
  done
  echo "$n"
}

send_smtp() {
  local i=0
  for file in "$OUT"/messages/*.eml; do
    local to from
    to="$(jq -r ".cases[$i].to" "$OUT/manifest.json")"
    from="$(jq -r ".cases[$i].from" "$OUT/manifest.json")"
    swaks --silent 2 --server 127.0.0.1 --port 25 --from "$from" --to "$to" --data "@$file" \
      || { echo "SMTP recusou $(basename "$file")" >&2; return 1; }
    i=$((i + 1))
  done
  if [ "$MTA" = exim ]; then as_root exim4 -qff; else as_root postqueue -f; fi
}

mailbox_count() { # mensagens na caixa (new + cur)
  { as_root find "$MAILDIR/new" "$MAILDIR/cur" -type f 2>/dev/null || true; } | wc -l
}

case "$DELIVERY" in
  pipe)
    script="$(mktemp --suffix=.php)"
    node --experimental-strip-types --no-warnings "$HERE/build-pipe-script.mjs" "$INBOUND_URL" "$SECRET" > "$script"
    "$HERE/setup-mta.sh" "$HOSTING" pipe "$script"
    send_smtp
    rows="$(wait_rows "$EXPECTED_ROWS" 90)"
    add_check pipe-entregas "$([ "$rows" -ge "$EXPECTED_ROWS" ] && echo 1 || echo 0)" "$rows de $EXPECTED_ROWS mensagens chegaram pelo pipe"
    if [ "$MTA" = exim ]; then
      queued="$(as_root exim4 -bpc)"
      add_check fila-do-mta "$([ "$queued" = 0 ] && echo 1 || echo 0)" "$queued mensagens presas na fila do Exim"
    else
      queued="$(as_root postqueue -p | grep -c '^[0-9A-F]' || true)"
      add_check fila-do-mta "$([ "$queued" = 0 ] && echo 1 || echo 0)" "$queued mensagens presas na fila do Postfix"
    fi
    ;;
  imap-cron|imap-queue)
    "$HERE/setup-mta.sh" "$HOSTING" imap
    send_smtp
    for _ in $(seq 1 30); do [ "$(mailbox_count)" -ge 8 ] && break; sleep 1; done
    add_check caixa-recebeu "$([ "$(mailbox_count)" -ge 8 ] && echo 1 || echo 0)" "$(mailbox_count) mensagens entregues em $MAILDIR"
    if [ "$DELIVERY" = imap-cron ]; then
      # O cron do painel: `* * * * * php flarum schedule:run`.
      fphp flarum schedule:run
      rows="$(wait_rows "$EXPECTED_ROWS" 20)"
    else
      fphp flarum queue:work --sleep=1 --tries=1 > "$FORUM_DIR/storage/logs/queue.log" 2>&1 &
      echo $! > "$FORUM_DIR/storage/queue.pid"
      curl -s -o /dev/null http://127.0.0.1:8080/
      rows="$(wait_rows "$EXPECTED_ROWS" 150)"
      add_check fila-redis "$(grep -q 'FetchInboundEmailsJob' "$FORUM_DIR/storage/logs/queue.log" && echo 1 || echo 0)" "FetchInboundEmailsJob rodou no queue:work"
      kill "$(cat "$FORUM_DIR/storage/queue.pid")" 2>/dev/null || true
    fi
    add_check imap-coleta "$([ "$rows" -ge "$EXPECTED_ROWS" ] && echo 1 || echo 0)" "$rows de $EXPECTED_ROWS mensagens lidas da caixa"
    # Só o alias forjado fica na caixa (lido, não apagado): não é do fórum.
    left="$(mailbox_count)"
    unread="$({ as_root find "$MAILDIR/new" -type f 2>/dev/null || true; } | wc -l)"
    add_check imap-limpeza "$([ "$left" = 1 ] && [ "$unread" = 0 ] && echo 1 || echo 0)" "$left na caixa, $unread não lidas (esperado 1 e 0)"
    ;;
  webhook)
    fphp .hosting/webhook.php "$OUT" "$INBOUND_URL"
    ;;
esac

# Saída: o Reply-To assinado entra na notificação.
discussion="$(jq -r .discussion_id "$OUT/manifest.json")"
alice="$(jq -r .alice_id "$OUT/manifest.json")"
if out="$(fphp flarum mail-reply:simulate-send "$alice" "$discussion" 2>&1)"; then
  add_check reply-to-saida 1 "$(echo "$out" | grep -m1 'Reply-To: reply+' | sed 's/^ *//')"
else
  add_check reply-to-saida 0 "simulate-send falhou"
fi

if [ ${#extra[@]} -gt 0 ]; then
  existing="$( [ -f "$OUT/extra-checks.json" ] && cat "$OUT/extra-checks.json" || echo '[]')"
  printf '%s\n' "${extra[@]}" | jq -s --argjson prev "$existing" '$prev + .' > "$OUT/extra-checks.json"
fi

status=0
fphp .hosting/scenario.php assert "$OUT" || status=$?

mkdir -p "$RESULTS"
cp "$OUT/result.json" "$RESULTS/$HOSTING-$DELIVERY.json"
cp "$OUT/result.md" "$RESULTS/$HOSTING-$DELIVERY.md"
# Logs para investigar uma célula vermelha.
mkdir -p "$RESULTS/logs/$HOSTING-$DELIVERY"
cp "$FORUM_DIR"/storage/logs/*.log "$RESULTS/logs/$HOSTING-$DELIVERY/" 2>/dev/null || true
as_root sh -c "cp /var/log/exim4/mainlog /var/log/postfix.log /var/log/dovecot.log '$RESULTS/logs/$HOSTING-$DELIVERY/' 2>/dev/null" || true
# Os logs do MTA são do root: o upload do artefato precisa lê-los.
as_root chown -R "$(id -u):$(id -g)" "$RESULTS/logs" 2>/dev/null || true
chmod -R u+rwX,go+rX "$RESULTS/logs" 2>/dev/null || true
exit "$status"
