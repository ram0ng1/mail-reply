<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Sem efeito: a marca "via e-mail" fica em `mail_reply_posts`. Mantida só
 * para instalações que já a registraram, que recebem o backfill e a remoção
 * da coluna em 2026_05_17_000002.
 */
return [
    'up' => function (Builder $schema) {
    },
    'down' => function (Builder $schema) {
    },
];
