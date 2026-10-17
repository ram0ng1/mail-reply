<?php

namespace Ramon\MailReply\Inbound;

use RuntimeException;

/**
 * Rejeição definitiva de uma mensagem — reprocessar não muda o resultado.
 * `silent` marca mensagens descartadas sem ruído (auto-respostas,
 * duplicatas); as demais são registradas como `rejected`. Qualquer outra
 * exceção do processor é tratada como falha transitória.
 */
class InboundRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly bool $silent = false,
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
