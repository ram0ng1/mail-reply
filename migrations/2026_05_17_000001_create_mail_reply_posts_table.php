<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tabela 1:1 com `posts`, para não alterar uma tabela do core. O backfill
 * da antiga coluna `posts.via_mail_reply` fica na migration seguinte.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_posts')) {
            return;
        }

        $schema->create('mail_reply_posts', function (Blueprint $table) {
            $table->unsignedInteger('post_id')->primary();
            $table->foreign('post_id')
                ->references('id')->on('posts')
                ->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('mail_reply_posts');
    },
];
