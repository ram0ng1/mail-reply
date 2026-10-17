<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Support;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Ramon\MailReply\Service\SecretBox;

final class Secrets
{
    public static function box(string $url = 'https://forum.example.com', string $base = '/srv/flarum'): SecretBox
    {
        return new SecretBox(
            new Config(['url' => $url]),
            new Paths(['base' => $base, 'public' => $base.'/public', 'storage' => $base.'/storage']),
        );
    }
}
