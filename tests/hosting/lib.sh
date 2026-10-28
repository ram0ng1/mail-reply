# Funções comuns dos scripts da matriz de hospedagem.

log() {
  printf '\033[1;34m[hosting]\033[0m %s\n' "$*"
}

# Root no contêiner, sudo no runner do GitHub.
as_root() {
  if [ "$(id -u)" = 0 ]; then "$@"; else sudo "$@"; fi
}

wait_port() {
  local port="$1"
  for _ in $(seq 1 60); do
    if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then return 0; fi
    sleep 0.5
  done
  echo "porta $port não abriu" >&2
  return 1
}

# PHP do fórum com o php.ini do perfil (disable_functions, open_basedir,
# memory_limit) somado ao do sistema, igual à conta no painel.
fphp() {
  PHP_INI_SCAN_DIR=":$FORUM_DIR/.hosting-ini" php "$@"
}

# Para um serviço que o apt pode ter subido pelo systemd (runner do GitHub)
# e espera o processo sair; sem isso o novo daemon acha o antigo ainda vivo.
stop_daemon() {
  local name="$1"
  if command -v systemctl >/dev/null 2>&1; then as_root systemctl stop "$name" >/dev/null 2>&1 || true; fi
  as_root pkill -x "$name" 2>/dev/null || true
  for _ in $(seq 1 40); do
    pgrep -x "$name" >/dev/null 2>&1 || return 0
    sleep 0.25
  done
  as_root pkill -9 -x "$name" 2>/dev/null || true
}
