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
 * Expõe o boolean somente-leitura `viaMailReply` no PostResource para o
 * badge do fórum. A fonte de verdade é a companion row `mailReplyMeta`,
 * eager-loaded pelos endpoints que serializam posts (ver extend.php) —
 * uma única query `whereIn` por resposta em vez de uma por post. Caminhos
 * não cobertos pelo eager-load caem no lazy-load da relação.
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
