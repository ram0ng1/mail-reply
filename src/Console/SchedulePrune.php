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

use Illuminate\Console\Scheduling\Event;

class SchedulePrune
{
    public function __invoke(Event $event): void
    {
        $event->daily();
    }
}
