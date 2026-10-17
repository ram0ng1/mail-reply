<?php

use Illuminate\Database\Schema\Builder;

/**
 * Aposentada. A marca "via e-mail" mora na companion table
 * `mail_reply_posts` (2026_05_17_000001); a coluna em `posts` só existia
 * para ser removida logo depois. Instalações novas pulam os dois ALTERs
 * em `posts`; instalações antigas já registraram esta migration e
 * recebem o backfill + drop em 2026_05_17_000002.
 */
return [
    'up' => function (Builder $schema) {
    },
    'down' => function (Builder $schema) {
    },
];
