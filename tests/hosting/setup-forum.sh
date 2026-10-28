#!/usr/bin/env bash
# Monta o fórum Flarum 2 de uma célula da matriz: instala o core com a
# extensão (cópia, não symlink, como num `composer require` de verdade),
# aplica o php.ini do perfil do painel e sobe o servidor web. Na entrega
# imap-queue troca a fila sync pela do Redis (fof/redis) e sobe o
# `queue:work`.
#
#   FORUM_DIR=/srv/forum tests/hosting/setup-forum.sh <hosting> <delivery>
#
# FORUM_TEMPLATE aponta para um fórum já criado (com ramon/mail-reply,
# webklex/php-imap e fof/redis instalados) e poupa o composer na execução
# local.
set -euo pipefail
if [ -n "${HOSTING_DEBUG:-}" ]; then set -x; fi

HOSTING="$1"
DELIVERY="$2"
HERE="$(cd "$(dirname "$0")" && pwd)"
EXT_DIR="$(cd "$HERE/../.." && pwd)"
export FORUM_DIR="${FORUM_DIR:-/srv/forum}"
# shellcheck source=/dev/null
source "$HERE/profiles/$HOSTING.env"
source "$HERE/lib.sh"

if [ ! -f "$FORUM_DIR/flarum" ]; then
  if [ -n "${FORUM_TEMPLATE:-}" ]; then
    log "copiando o fórum de $FORUM_TEMPLATE"
    as_root mkdir -p "$FORUM_DIR"
    as_root cp -a "$FORUM_TEMPLATE/." "$FORUM_DIR/"
    as_root rm -f "$FORUM_DIR/config.php" "$FORUM_DIR/flarum.sqlite"
  else
    log "composer create-project flarum/flarum"
    as_root mkdir -p "$FORUM_DIR"
    as_root chown "$(id -u):$(id -g)" "$FORUM_DIR"
    composer create-project --no-interaction --prefer-dist --no-progress "flarum/flarum:^2.0.0" "$FORUM_DIR"
    composer --working-dir="$FORUM_DIR" config repositories.ext \
      "{\"type\":\"path\",\"url\":\"$EXT_DIR\",\"options\":{\"symlink\":false}}"
    composer --working-dir="$FORUM_DIR" require --no-interaction --no-progress \
      "ramon/mail-reply:*@dev" "webklex/php-imap:^6.2" "fof/redis:^2.0"
  fi
fi

# A extensão fica dentro do vendor do site, como num host real: o
# open_basedir do DirectAdmin e da Plesk barraria um symlink para fora.
target="$FORUM_DIR/vendor/ramon/mail-reply"
as_root rm -rf "$target"
as_root mkdir -p "$target"
(cd "$EXT_DIR" && tar --exclude=./vendor --exclude=./js/node_modules --exclude=./.git -cf - .) | as_root tar -xf - -C "$target"
as_root chown -R "$(id -u):$(id -g)" "$FORUM_DIR"

# php.ini do perfil, valendo para o servidor web e para o CLI (cron).
mkdir -p "$FORUM_DIR/.hosting-ini"
{
  echo "memory_limit = $PHP_MEMORY_LIMIT"
  [ -n "$PHP_DISABLE_FUNCTIONS" ] && echo "disable_functions = $PHP_DISABLE_FUNCTIONS"
  [ -n "$PHP_OPEN_BASEDIR" ] && echo "open_basedir = ${PHP_OPEN_BASEDIR//__FORUM__/$FORUM_DIR}"
  echo "max_execution_time = 30"
} > "$FORUM_DIR/.hosting-ini/zz-hosting.ini"

cd "$FORUM_DIR"
cat > install.yml <<EOF
debug: false
baseUrl: http://127.0.0.1:8080
databaseConfiguration:
  driver: sqlite
  database: flarum.sqlite
adminUser:
  username: admin
  password: admin12345
  email: admin@forum.test
settings:
  forum_title: Mail Reply Hosting Test
EOF
fphp flarum install --file=install.yml >/dev/null
fphp flarum extension:enable flarum-mentions >/dev/null 2>&1 || true
fphp flarum extension:enable ramon-mail-reply

if [ "$DELIVERY" = imap-queue ]; then
  command -v redis-server >/dev/null || as_root apt-get install -y -qq --no-install-recommends redis-server >/dev/null
  redis-cli ping >/dev/null 2>&1 || redis-server --daemonize yes --save '' --appendonly no >/dev/null
  cat > extend.php <<'EOF'
<?php

use Flarum\Extend;

return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 5,
    ]))->disable(['session', 'settings']),
];
EOF
fi
fphp flarum cache:clear >/dev/null

pkill -f '^php -S 127.0.0.1:8080' 2>/dev/null || true
PHP_INI_SCAN_DIR=":$FORUM_DIR/.hosting-ini" nohup php -S 127.0.0.1:8080 -t public > "$FORUM_DIR/storage/logs/php-server.log" 2>&1 &
wait_port 8080
code="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/)"
log "fórum no ar (HTTP $code)"
[ "$code" = 200 ]
