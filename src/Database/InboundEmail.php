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

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * Registro de cada mensagem recebida, identificada pelo SHA-256 do
 * Message-ID. O índice único em `message_hash` é a barreira de dedupe
 * contra re-fetch do IMAP e retries de webhook; o restante é auditoria.
 *
 * @property int $id
 * @property string $message_hash
 * @property int|null $user_id
 * @property int|null $discussion_id
 * @property int|null $post_id
 * @property string $status
 * @property string|null $reason
 * @property Carbon $created_at
 */
class InboundEmail extends AbstractModel
{
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_IGNORED = 'ignored';

    protected $table = 'mail_reply_inbound';
    public $timestamps = false;
    protected $casts = [
        'user_id' => 'int',
        'discussion_id' => 'int',
        'post_id' => 'int',
        'created_at' => 'datetime',
    ];
}
