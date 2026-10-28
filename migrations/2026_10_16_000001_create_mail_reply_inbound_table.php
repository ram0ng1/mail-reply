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
 * Log de mensagens recebidas: dedupe por Message-ID (índice único) e
 * contagem de respostas recentes por usuário (limite por hora).
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_inbound')) {
            return;
        }

        $schema->create('mail_reply_inbound', function (Blueprint $table) {
            $table->increments('id');
            $table->char('message_hash', 64)->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('discussion_id')->nullable();
            $table->unsignedInteger('post_id')->nullable();
            $table->string('status', 16);
            $table->string('reason', 64)->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'status', 'created_at']);
            $table->index('created_at');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('mail_reply_inbound');
    },
];
