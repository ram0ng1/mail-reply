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

use Psr\Log\AbstractLogger;
use Stringable;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['message'],
            array_filter($this->records, static fn (array $r): bool => $r['level'] === 'error'),
        ));
    }
}
