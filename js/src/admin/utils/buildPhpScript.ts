/**
 * Escapa um valor para dentro de uma string PHP entre aspas simples.
 */
function phpString(value: string): string {
  return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

/**
 * Gera o script de pipe do MTA com a URL e o segredo atuais. O endpoint
 * responde 202 ao aceitar (processamento assíncrono, sem post_id); 5xx e
 * falhas de curl viram TEMPFAIL (75) para o MTA retentar, o resto PERMFAIL (65).
 */
export default function buildPhpScript(apiUrl: string, secret: string): string {
  return `#!/usr/bin/env php
<?php
/**
 * Mail Reply — inbound forwarder for Flarum.
 * Save as /usr/local/bin/forward-to-flarum.php, chmod 700 and chown it to the
 * user the mail server runs the pipe as (it holds the webhook secret), then create a
 * Pipe forwarder in DirectAdmin/cPanel: |/usr/local/bin/php /usr/local/bin/forward-to-flarum.php
 * (check the PHP path with "which php"). DirectAdmin only accepts pipe
 * destinations when directadmin.conf has allow_forwarder_pipe=1; on cPanel use
 * Forwarders -> Add Forwarder -> Advanced Options -> Pipe to a Program.
 *
 * Logs:
 *   - syslog tag "forward-to-flarum"  (journalctl -t forward-to-flarum)
 *   - /tmp/forward-to-flarum.last     (date + HTTP status of the last run)
 *
 * Exit codes:
 *   0 → accepted    75 → TEMPFAIL (MTA retries)    65 → PERMFAIL (bounce)
 */

$FLARUM_URL     = ${phpString(apiUrl)};
$WEBHOOK_SECRET = ${phpString(secret || 'PASTE_THE_WEBHOOK_SECRET_HERE')};

openlog('forward-to-flarum', LOG_PID | LOG_NDELAY, LOG_MAIL);

function finish(int $priority, string $msg, int $code): void
{
    syslog($priority, $msg);
    closelog();
    exit($code);
}

syslog(LOG_INFO, sprintf('INVOKED user=%s pid=%d', get_current_user(), getmypid()));

$body = (string) stream_get_contents(STDIN);
$bodyLen = strlen($body);
syslog(LOG_INFO, "STDIN bytes={$bodyLen}");

if ($bodyLen === 0) {
    finish(LOG_ERR, 'PERMFAIL stdin_empty', 65);
}
if (! function_exists('curl_init')) {
    finish(LOG_ERR, 'TEMPFAIL php-curl is required', 75);
}

$ch = curl_init($FLARUM_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ["X-Mail-Reply-Token: {$WEBHOOK_SECRET}", 'Content-Type: message/rfc822'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
unset($ch);

@file_put_contents('/tmp/forward-to-flarum.last', sprintf("%s HTTP %d\\n", date('c'), $status));

$decoded = json_decode(is_string($response) ? $response : '', true);
$error = is_array($decoded) && is_string($decoded['error'] ?? null)
    ? substr(preg_replace('/[^A-Za-z0-9_]/', '', $decoded['error']), 0, 64)
    : '-';

if ($err !== '') {
    finish(LOG_ERR, "TEMPFAIL curl={$err}", 75);
}
if ($status === 0 || $status >= 500) {
    finish(LOG_ERR, "TEMPFAIL http={$status} error={$error}", 75);
}
if ($status < 200 || $status >= 300) {
    finish(LOG_ERR, "PERMFAIL http={$status} error={$error}", 65);
}

finish(LOG_INFO, "ACCEPTED http={$status} (queued for processing)", 0);
`;
}
