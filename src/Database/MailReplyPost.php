<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Database;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Companion 1:1 do `posts` — extensão jamais ALTERa tabela do core. A
 * simples existência da row marca o post como criado via
 * reply por e-mail; não há colunas adicionais por enquanto, mas a tabela
 * está pronta pra carregar metadata futura (provider, signature
 * algorithm, timestamps de inbound) sem mexer no `posts`.
 *
 * @property int $post_id
 */
class MailReplyPost extends AbstractModel
{
    protected $table = 'mail_reply_posts';
    protected $primaryKey = 'post_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $casts = ['post_id' => 'int'];

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
