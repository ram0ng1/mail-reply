#!/usr/bin/env bash
# Sobe o servidor de e-mail do perfil de hospedagem: Exim (cPanel,
# DirectAdmin) ou Postfix (Plesk, VPS), Dovecot com IMAP 143/993 e, para a
# entrega por pipe, o script gerado pelo admin no caminho que o painel usa.
#
#   tests/hosting/setup-mta.sh <hosting> <delivery> <pipe-script.php>
#
# Roda como root (contêiner) ou com sudo (runner do GitHub). Serviços sobem
# direto pelo binário, sem systemd, para funcionar igual nos dois lugares.
set -euo pipefail
if [ -n "${HOSTING_DEBUG:-}" ]; then set -x; fi

HOSTING="$1"
DELIVERY="$2"
PIPE_SOURCE="${3:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "$HERE/profiles/$HOSTING.env"
source "$HERE/lib.sh"

IMAP_USER=reply@forum.test
IMAP_PASSWORD=imap-secret

log "MTA $MTA ($HOSTING), entrega $DELIVERY"

grep -q 'imap.forum.test' /etc/hosts || echo '127.0.0.1 mail.forum.test imap.forum.test forum.test' | as_root tee -a /etc/hosts >/dev/null

# Pacotes. Exim e Postfix são excludentes; o Postfix pede debconf.
export DEBIAN_FRONTEND=noninteractive
if [ "$MTA" = exim ]; then
  pkgs="exim4-daemon-light"
else
  echo 'postfix postfix/main_mailer_type select No configuration' | as_root debconf-set-selections
  pkgs="postfix"
fi
as_root apt-get install -y -qq --no-install-recommends $pkgs dovecot-imapd dovecot-lmtpd swaks openssl ca-certificates >/dev/null

# Certificado do IMAP. Nos painéis vem de uma CA instalada no sistema (o
# AutoSSL/Let's Encrypt de produção); no VPS é autoassinado e o teste roda
# com a validação desligada, como o admin faria.
tmpcert="$(mktemp -d)"
if [ "$IMAP_VALIDATE_CERT" = 1 ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=Mail Reply Test CA' \
    -keyout "$tmpcert/ca.key" -out "$tmpcert/ca.pem" 2>/dev/null
  openssl req -newkey rsa:2048 -nodes -subj '/CN=imap.forum.test' \
    -keyout "$tmpcert/key.pem" -out "$tmpcert/req.csr" 2>/dev/null
  printf 'subjectAltName=DNS:imap.forum.test,DNS:mail.forum.test\n' > "$tmpcert/ext.cnf"
  openssl x509 -req -in "$tmpcert/req.csr" -CA "$tmpcert/ca.pem" -CAkey "$tmpcert/ca.key" -CAcreateserial \
    -days 2 -extfile "$tmpcert/ext.cnf" -out "$tmpcert/cert.pem" 2>/dev/null
  as_root cp "$tmpcert/ca.pem" /usr/local/share/ca-certificates/mail-reply-test-ca.crt
  as_root update-ca-certificates >/dev/null
else
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=imap.forum.test' \
    -keyout "$tmpcert/key.pem" -out "$tmpcert/cert.pem" 2>/dev/null
fi
as_root install -m 0644 "$tmpcert/cert.pem" /etc/dovecot/mr-cert.pem
as_root install -m 0600 "$tmpcert/key.pem" /etc/dovecot/mr-key.pem
rm -rf "$tmpcert"

# Conta do painel e binário do PHP no caminho que o painel usa.
id "$ACCOUNT_USER" >/dev/null 2>&1 || as_root useradd -m -s /bin/bash -G mail "$ACCOUNT_USER"
if [ "$PIPE_PHP" != "$(command -v php)" ]; then
  as_root mkdir -p "$(dirname "$PIPE_PHP")"
  as_root ln -sf "$(command -v php)" "$PIPE_PHP"
fi

uid="$(id -u "$ACCOUNT_USER")"
gid="$(id -g "$ACCOUNT_USER")"
maildir_root="$MAILDIR"
if [ "$MTA" = postfix ]; then
  # Dovecot LMTP grava como o usuário virtual.
  maildir_root="$MAILDIR"
fi

if [ "$DELIVERY" = pipe ]; then
  [ -n "$PIPE_SOURCE" ] || { echo "falta o script de pipe" >&2; exit 1; }
  as_root mkdir -p "$(dirname "$PIPE_SCRIPT")"
  as_root install -m 0700 "$PIPE_SOURCE" "$PIPE_SCRIPT"
  # O script cai no /tmp do usuário que o MTA usa; nada mais a preparar.
  as_root chown "$ACCOUNT_USER" "$PIPE_SCRIPT"
  pipe_cmd="|$PIPE_PHP $PIPE_SCRIPT"
fi

# Dovecot: um usuário, a caixa de respostas.
as_root mkdir -p "$maildir_root"
# No Exim o grupo é mail (como nos painéis); o Dovecot LMTP do Postfix grava
# com o grupo do próprio usuário virtual e não consegue trocar para mail.
mail_group=mail
if [ "$MTA" = postfix ]; then mail_group="$(id -gn "$ACCOUNT_USER")"; fi
as_root chown -R "$ACCOUNT_USER:$mail_group" "$(dirname "$maildir_root")"
as_root chmod -R 0770 "$(dirname "$maildir_root")"
printf '%s:{PLAIN}%s:%s:%s::%s\n' "$IMAP_USER" "$IMAP_PASSWORD" "$uid" "$gid" "$(dirname "$maildir_root")" \
  | as_root tee /etc/dovecot/mr-users >/dev/null
as_root chmod 0640 /etc/dovecot/mr-users
as_root chgrp dovecot /etc/dovecot/mr-users
{
  sed "s#__MAILDIR__#$maildir_root#" "$HERE/mta/dovecot.conf"
  if [ "$MTA" = postfix ]; then cat "$HERE/mta/dovecot-lmtp.conf"; fi
} | as_root tee /etc/dovecot/dovecot.conf >/dev/null

case "$MTA" in
  exim)
    as_root cp "$HERE/mta/exim-$EXIM_FLAVOR.conf" /etc/exim4/exim4.conf
    if [ "$EXIM_FLAVOR" = cpanel ]; then
      as_root mkdir -p /etc/valiases
      if [ "$DELIVERY" = pipe ]; then
        printf 'reply@forum.test: "%s"\n' "$pipe_cmd" | as_root tee /etc/valiases/forum.test >/dev/null
      else
        : | as_root tee /etc/valiases/forum.test >/dev/null
      fi
    else
      as_root mkdir -p /etc/virtual/forum.test
      echo forum.test | as_root tee /etc/virtual/domains >/dev/null
      echo "forum.test: $ACCOUNT_USER" | as_root tee /etc/virtual/domainowners >/dev/null
      if [ "$DELIVERY" = pipe ]; then
        printf 'reply: "%s"\n' "$pipe_cmd" | as_root tee /etc/virtual/forum.test/aliases >/dev/null
        : | as_root tee /etc/virtual/forum.test/passwd >/dev/null
      else
        : | as_root tee /etc/virtual/forum.test/aliases >/dev/null
        echo 'reply:x' | as_root tee /etc/virtual/forum.test/passwd >/dev/null
      fi
    fi
    if [ "$DELIVERY" != pipe ] && [ "$EXIM_FLAVOR" = cpanel ]; then
      as_root mkdir -p "$MAILDIR"
      as_root chown -R "$ACCOUNT_USER:$ACCOUNT_USER" "/home/$ACCOUNT_USER/mail"
    fi
    as_root mkdir -p /var/log/exim4 /var/spool/exim4
    as_root chown -R Debian-exim:Debian-exim /var/log/exim4 /var/spool/exim4
    as_root exim4 -bV >/dev/null
    stop_daemon exim4
    as_root exim4 -bd -q15s
    ;;
  postfix)
    as_root cp "$HERE/mta/postfix-main.cf" /etc/postfix/main.cf
    [ -f /etc/postfix/master.cf ] || as_root cp /usr/share/postfix/master.cf.dist /etc/postfix/master.cf
    if [ "$DELIVERY" = pipe ]; then
      echo 'reply@forum.test flarumpipe@localhost' | as_root tee /etc/postfix/virtual >/dev/null
      : | as_root tee /etc/postfix/vmailbox >/dev/null
      printf 'postmaster: root\nflarumpipe: "%s"\n' "$pipe_cmd" | as_root tee /etc/aliases >/dev/null
    else
      : | as_root tee /etc/postfix/virtual >/dev/null
      echo 'reply@forum.test OK' | as_root tee /etc/postfix/vmailbox >/dev/null
      printf 'postmaster: root\n' | as_root tee /etc/aliases >/dev/null
    fi
    as_root postmap /etc/postfix/virtual /etc/postfix/vmailbox
    as_root newaliases
    # O local(8) roda o comando como o dono do banco de aliases; o
    # postalias, por sua vez, roda como o dono do arquivo-fonte. Por isso o
    # chown vem depois do newaliases.
    if [ "$DELIVERY" = pipe ]; then as_root chown "$ACCOUNT_USER" /etc/aliases /etc/aliases.db; fi
    as_root postfix check
    if command -v systemctl >/dev/null 2>&1; then as_root systemctl stop postfix >/dev/null 2>&1 || true; fi
    as_root postfix stop >/dev/null 2>&1 || true
    as_root postfix start
    ;;
esac

stop_daemon dovecot
as_root rm -f /run/dovecot/master.pid
as_root dovecot

wait_port 25
wait_port 143
wait_port 993
log "MTA pronto"
