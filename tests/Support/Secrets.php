<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Support;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Ramon\MailReply\Service\SecretBox;

final class Secrets
{
    /**
     * `$storage` null cria uma pasta temporária nova (sem chave ainda).
     */
    public static function box(string $url = 'https://forum.example.com', string $base = '/srv/flarum', ?string $storage = null): SecretBox
    {
        return new SecretBox(
            new Config(['url' => $url]),
            new Paths(['base' => $base, 'public' => $base.'/public', 'storage' => $storage ?? self::storage()]),
        );
    }

    public static function storage(): string
    {
        $dir = sys_get_temp_dir().'/mail-reply-secretbox-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }
}
