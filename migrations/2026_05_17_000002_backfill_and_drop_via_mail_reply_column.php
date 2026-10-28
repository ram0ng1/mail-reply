<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Copia `posts.via_mail_reply` para `mail_reply_posts` e remove a coluna.
 * Só query builder: `INSERT IGNORE` e `= 1` em coluna boolean quebram no
 * PostgreSQL. O `whereNotExists` deixa o backfill idempotente em qualquer banco.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('posts') || ! $schema->hasColumn('posts', 'via_mail_reply')) {
            return;
        }

        $db = $schema->getConnection();

        $db->table('mail_reply_posts')->insertUsing(
            ['post_id'],
            $db->table('posts')
                ->select('posts.id')
                ->where('posts.via_mail_reply', true)
                ->whereNotExists(function (QueryBuilder $query) {
                    $query->selectRaw('1')
                        ->from('mail_reply_posts')
                        ->whereColumn('mail_reply_posts.post_id', 'posts.id');
                })
        );

        $schema->table('posts', function (Blueprint $table) {
            $table->dropColumn('via_mail_reply');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('posts')) {
            return;
        }

        if (! $schema->hasColumn('posts', 'via_mail_reply')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->boolean('via_mail_reply')->default(false);
            });
        }

        if ($schema->hasTable('mail_reply_posts')) {
            $db = $schema->getConnection();

            $db->table('posts')
                ->whereIn('id', $db->table('mail_reply_posts')->select('post_id'))
                ->update(['via_mail_reply' => true]);
        }
    },
];
