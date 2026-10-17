# Mail Reply — Flarum 2

Reply-by-email for Flarum 2, modelled on Discourse and GitLab: members answer a
notification email and the reply shows up as a post in the discussion.

## How it works

1. **Signed Reply-To on every notification.** When Flarum emails a notification
   about a public discussion, the extension adds:

   ```text
   Reply-To: reply+u42d123p987-9f0a1b2c3d4e5f6a@reply.example.com
   ```

   The tag encodes the recipient, the discussion and (when the notification is
   about a specific post) that post, signed with an HMAC key generated on first
   use and stored encrypted (XChaCha20-Poly1305) in the settings table. Outgoing
   mail also carries `Auto-Submitted: auto-generated` and
   `X-Auto-Response-Suppress: All` so vacation responders stay quiet.

2. **Inbound processing.** Replies reach Flarum through one of two delivery
   modes (choose one in the admin panel):

   - **IMAP** — a dedicated mailbox that accepts any `+tag` is polled every
     minute (requires `composer require webklex/php-imap`).
   - **Webhook** — `POST /api/mail-reply/inbound`, fed by Mailgun, Postmark,
     Cloudflare Email Workers or the PHP pipe script shown in the admin panel.

3. **Checks before posting.** A message becomes a post only if:

   - the reply address signature is valid;
   - the sender is the account's confirmed email address (configurable);
   - the discussion is visible to the user, public (not a private/byobu
     thread) and the user may reply to it;
   - it is not an auto-reply, bounce/DSN, mailer-daemon message or flagged
     spam (RFC 3834 / Discourse rules);
   - it has not been processed before (Message-ID dedupe);
   - the user is under the hourly limit (default 20, `postWithoutThrottle`
     bypasses it) and the body fits in a post.

   Quoted history and signatures are stripped (multi-language quote headers,
   `willdurand/email-reply-parser`, or the provider's own stripped text).
   Replies to a specific post are prefixed with `@"author"#p{id}` so they render
   as a reply to it. Posts get a small "via email" badge.

## Install

```bash
composer require ramon/mail-reply
composer require webklex/php-imap   # only for IMAP mode
php flarum migrate
php flarum extension:enable ramon-mail-reply
```

## Delivery modes

### IMAP

Fill host, port, encryption, username, password and folder under
**Admin → Mail Reply → IMAP**, then use **Test IMAP connection**. Use a
**dedicated** mailbox: messages that are not replies to forum aliases are only
marked as read, but they are never posted.

Polling runs:

- inside `php flarum queue:work` when a real queue driver (Redis, database…)
  is configured — a self-rescheduling job, no cron needed;
- otherwise through the Flarum scheduler, so add the standard cron entry:

  ```cron
  * * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1
  ```

Manual run: `php flarum mail-reply:fetch --verbose-status`.

### Webhook

```http
POST https://yourforum.tld/api/mail-reply/inbound
X-Mail-Reply-Token: <webhook secret from the admin panel>
```

Accepted bodies: raw RFC 822 (`Content-Type: message/rfc822`), Postmark JSON,
Mailgun form posts, or canonical JSON `{to, from, subject, body_text,
body_html}`. The PHP pipe script shown in the admin panel is the fastest path
(about one second from delivery to post). On DirectAdmin, pipe forwarders
(`|/usr/local/bin/php /path/forward-to-flarum.php`) are only accepted when
`allow_forwarder_pipe=1` is set in `directadmin.conf`; on cPanel use
*Forwarders → Pipe to a Program*. Mailgun Routes can be authenticated with the account's webhook
signing key instead of the header. Responses: `202` accepted, `401` bad
credentials, `404` webhook mode inactive, `413` larger than 10 MB, `422`
unreadable payload, `500` could not queue (the provider should retry).

## Console commands

| Command | Purpose |
| --- | --- |
| `mail-reply:fetch` | Poll the IMAP mailbox once (`--loop`, `--interval`, `--limit`). |
| `mail-reply:diagnose <user> <discussion> [post]` | Mint an alias and verify the round trip (`--address=` to decode one). |
| `mail-reply:simulate-send <user> <discussion>` | Check that the Reply-To header is injected. |
| `mail-reply:prune` | Delete inbound log entries older than 90 days (scheduled daily). |

## Compatibility

Flarum 2.0.x (including 2.0.2), MySQL/MariaDB, PostgreSQL and SQLite.

## Development

```bash
composer install && composer test && composer analyse
cd js && npm ci && npm run check-typings && npm run build
```

CI/CD lives in `.github/workflows/`; configure the `FLARUM_API_KEY` secret and
the optional `FLARUM_DISCUSSION_ID` variable under
Settings → Secrets and variables → Actions.
