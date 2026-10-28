<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Api;

use Flarum\Api\Schema\Boolean;
use Flarum\Post\Post;

/**
 * Campo somente leitura `viaMailReply` usado pelo badge do fórum. A relação
 * `mailReplyMeta` é carregada em lote no extend.php.
 */
class ExposeViaMailReply
{
    public function __invoke(): array
    {
        return [
            Boolean::make('viaMailReply')
                ->get(fn (Post $post) => $post->mailReplyMeta !== null),
        ];
    }
}
