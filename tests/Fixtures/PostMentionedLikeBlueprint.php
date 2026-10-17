<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Fixtures;

use Flarum\Post\Post;

/**
 * Espelha o shape do `PostMentionedBlueprint` do flarum/mentions: `$post`
 * é o post citado (subject) e `$reply` o post novo de quem citou.
 */
final class PostMentionedLikeBlueprint
{
    public function __construct(public Post $post, public Post $reply)
    {
    }

    public function getSubject(): Post
    {
        return $this->post;
    }
}
