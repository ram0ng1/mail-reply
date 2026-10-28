<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

/**
 * Remove `mail_reply_tokens`, sem uso desde que os aliases passaram a ser
 * assinados com HMAC. Idempotente: em instalações novas a tabela não existe.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_tokens')) {
            $schema->drop('mail_reply_tokens');
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_tokens')) {
            return;
        }

        $schema->create('mail_reply_tokens', function ($table) {
            $table->string('token', 64)->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('post_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();

            $table->index(['user_id', 'discussion_id']);
            $table->index('expires_at');
        });
    },
];
