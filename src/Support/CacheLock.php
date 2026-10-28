<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Support;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * O contrato `Repository` não declara `lock()`; o repositório do Laravel o
 * repassa ao store por `__call`. Aqui a mesma coisa, com tipo.
 */
final class CacheLock
{
    public static function make(Repository $cache, string $name, int $seconds): Lock
    {
        $store = $cache instanceof CacheRepository ? $cache->getStore() : null;

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The cache store does not support atomic locks.');
        }

        return $store->lock($name, $seconds);
    }
}
