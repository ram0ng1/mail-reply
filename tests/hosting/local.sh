#!/usr/bin/env bash
# Roda a matriz 4×4 localmente, cada célula num contêiner novo (o MTA de uma
# não vaza para a outra). Precisa de Docker, Node 22 no host e de um fórum
# modelo já instalado com composer (FORUM_TEMPLATE).
#
#   FORUM_TEMPLATE=/caminho/do/forum tests/hosting/local.sh [hosting] [delivery]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
EXT="$(cd "$HERE/../.." && pwd)"
: "${FORUM_TEMPLATE:?defina FORUM_TEMPLATE}"
NODE_BIN="$(readlink -f "$(command -v node)")"
docker build -q -t mail-reply-hosting "$HERE" >/dev/null
hostings=("${1:-cpanel directadmin plesk vps}")
deliveries=("${2:-pipe imap-cron imap-queue webhook}")
mkdir -p "$HERE/results"
for h in ${hostings[@]}; do
  for d in ${deliveries[@]}; do
    echo "=== $h × $d"
    docker run --rm --network host \
      -v "$EXT:$EXT" -v "$FORUM_TEMPLATE:/template:ro" -v "$NODE_BIN:/usr/local/bin/node:ro" \
      -e FORUM_TEMPLATE=/template -e COMPOSER_ALLOW_SUPERUSER=1 \
      mail-reply-hosting bash -c "apt-get update -qq >/dev/null 2>&1 || true; $HERE/run-cell.sh $h $d $HERE/results" \
      > "$HERE/results/$h-$d.log" 2>&1 && echo ok || echo FALHOU
  done
done
