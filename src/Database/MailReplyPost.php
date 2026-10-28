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
 * Tabela 1:1 com `posts`, para não alterar uma tabela do core. A existência
 * da linha marca o post como criado por resposta de e-mail.
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
