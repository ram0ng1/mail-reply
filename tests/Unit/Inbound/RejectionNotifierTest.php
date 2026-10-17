<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Unit\Inbound;

use Flarum\Locale\TranslatorInterface;
use Flarum\User\DisplayName\UsernameDriver;
use Flarum\User\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\RejectionNotifier;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\MemoryLogger;
use Symfony\Component\Mime\Email;

final class RejectionNotifierTest extends TestCase
{
    /** @var array<int, Email> */
    private array $sent = [];

    private function notifier(array $settings = []): RejectionNotifier
    {
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->andReturnUsing(function (string $text, callable $callback) {
            $message = new Message((new Email())->text($text));
            $callback($message);
            $this->sent[] = $message->getSymfonyMessage();

            return null;
        });

        $translator = Mockery::mock(TranslatorInterface::class);
        $translator->shouldReceive('getLocale')->andReturn('en');
        $translator->shouldReceive('setLocale');
        $translator->shouldReceive('trans')->andReturnUsing(fn (string $key) => $key);

        return new RejectionNotifier(
            $mailer,
            $translator,
            new ArraySettings($settings + ['forum_title' => 'Forum', 'default_locale' => 'en']),
            new Repository(new ArrayStore()),
            new MemoryLogger(),
        );
    }

    private function user(): User
    {
        User::setDisplayNameDriver(new UsernameDriver());

        $user = new User();
        $user->id = 7;
        $user->username = 'alice';
        $user->email = 'alice@example.com';

        return $user;
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_sends_one_auto_replied_notice_per_reason_per_day(): void
    {
        $notifier = $this->notifier();

        $notifier->notify($this->user(), 'too_long', 'Re: hi');
        $notifier->notify($this->user(), 'too_long', 'Re: hi');
        $notifier->notify($this->user(), 'rate_limited', 'Re: hi');

        self::assertCount(2, $this->sent);
        self::assertSame('alice@example.com', $this->sent[0]->getTo()[0]->getAddress());
        self::assertSame('auto-replied', $this->sent[0]->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
    }

    public function test_ignores_reasons_the_user_cannot_act_on(): void
    {
        $notifier = $this->notifier();

        $notifier->notify($this->user(), 'sender_mismatch', 'Re: hi');
        $notifier->notify($this->user(), 'invalid_address', 'Re: hi');

        self::assertSame([], $this->sent);
    }

    public function test_respects_disabled_setting(): void
    {
        $this->notifier(['ramon-mail-reply.send_rejection_notices' => '0'])->notify($this->user(), 'too_long', 'Re: hi');

        self::assertSame([], $this->sent);
    }
}
