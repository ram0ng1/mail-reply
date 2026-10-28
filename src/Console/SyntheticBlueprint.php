<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Console;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\CommentPost;
use Flarum\User\User;

/**
 * Blueprint usado apenas pelo `mail-reply:simulate-send`; não é registrado
 * como notificação.
 */
class SyntheticBlueprint implements BlueprintInterface, MailableInterface
{
    public function __construct(
        public CommentPost $post,
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'synthetic', 'html' => 'synthetic'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return '[simulate-send]';
    }

    public static function getType(): string
    {
        return 'mail-reply.synthetic';
    }

    public static function getSubjectModel(): string
    {
        return CommentPost::class;
    }
}
