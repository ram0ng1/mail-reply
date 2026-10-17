<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Fixtures;

/**
 * Blueprint mínimo cujo subject é um model arbitrário — cobre tanto
 * notificações de post (mention) quanto de discussão (subscriptions).
 */
final class SubjectBlueprint
{
    public function __construct(private ?object $subject)
    {
    }

    public function getSubject(): ?object
    {
        return $this->subject;
    }
}
