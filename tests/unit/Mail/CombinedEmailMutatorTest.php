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

namespace Ramon\MailReply\Tests\unit\Mail;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Mail\MutateEmail;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramon\MailReply\Mail\CombinedEmailMutator;
use Ramon\MailReply\Tests\Fixtures\PostMentionedLikeBlueprint;
use Ramon\MailReply\Tests\Fixtures\SubjectBlueprint;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\Database;
use Ramon\MailReply\Tests\Support\MemoryLogger;
use Ramon\MailReply\Token\TokenService;
use ReflectionMethod;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class CombinedEmailMutatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ?Connection $db = null;

    /** @var TokenService&MockInterface */
    private TokenService $tokens;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->db = Database::use('sqlite');
        if ($this->db === null) {
            self::markTestSkipped('SQLite is not available.');
        }

        $schema = $this->db->getSchemaBuilder();
        $schema->dropIfExists('discussions');
        $schema->create('discussions', function (Blueprint $t) {
            $t->increments('id');
            $t->string('title')->nullable();
            $t->boolean('is_private')->default(false);
        });
        $this->db->table('discussions')->insert([
            ['id' => 3, 'title' => 'Public', 'is_private' => false],
            ['id' => 4, 'title' => 'Private', 'is_private' => true],
        ]);

        $this->tokens = Mockery::mock(TokenService::class);
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->db?->getSchemaBuilder()->dropIfExists('discussions');
        $this->db = null;
    }

    private function mutator(string $mode = 'imap'): CombinedEmailMutator
    {
        return new CombinedEmailMutator(
            $this->tokens,
            new LogReporter($this->logger),
            new NullLogger(),
            new ArraySettings(['ramon-mail-reply.delivery_mode' => $mode]),
        );
    }

    public function test_disabled_mode_adds_no_reply_to(): void
    {
        $this->tokens->shouldNotReceive('mintAddress');
        $email = self::email();

        $this->mutator('disabled')->handle(new MessageSending($email, [
            'user' => (object) ['id' => 9],
            'blueprint' => new SubjectBlueprint(null),
        ]));

        self::assertNull($email->getHeaders()->get('Reply-To'));
        self::assertNull($email->getHeaders()->get('Auto-Submitted'));
    }

    private static function email(string $subject = 'John mentioned you in "Topic"'): Email
    {
        return (new Email())
            ->from('noreply@forum.test')
            ->to('user@example.com')
            ->subject($subject)
            ->text('body');
    }

    private static function user(int $id = 42): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }

    private static function post(int $id, int $discussionId): Post
    {
        $post = new CommentPost();
        $post->id = $id;
        $post->discussion_id = $discussionId;

        return $post;
    }

    public function test_does_not_extend_core_mutate_email(): void
    {
        self::assertFalse(is_subclass_of(CombinedEmailMutator::class, MutateEmail::class));
    }

    public function test_handle_has_no_return_type_and_returns_null(): void
    {
        $method = new ReflectionMethod(CombinedEmailMutator::class, 'handle');

        self::assertFalse($method->hasReturnType());

        $this->tokens->shouldReceive('mintAddress')->andReturn('reply+u42d3p7-0123456789abcdef@reply.forum.test');
        $event = new MessageSending(self::email(), ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(7, 3))]);

        self::assertNull($this->mutator()->handle($event));
    }

    public function test_until_keeps_calling_listeners_registered_after_the_mutator(): void
    {
        $this->tokens->shouldReceive('mintAddress')->andReturn('reply+u42d3p7-0123456789abcdef@reply.forum.test');

        $events = new Dispatcher(new Container());
        $events->listen(MessageSending::class, [$this->mutator(), 'handle']);
        $laterListenerRan = false;
        $events->listen(MessageSending::class, function () use (&$laterListenerRan) {
            $laterListenerRan = true;
        });

        $event = new MessageSending(self::email(), ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(7, 3))]);
        $result = $events->until($event);

        self::assertNull($result);
        self::assertTrue($laterListenerRan, 'A non-null return from the mutator would halt until() and starve other extensions.');
    }

    public function test_post_notification_gets_reply_to_suppression_headers_and_subject_marker(): void
    {
        $alias = 'reply+u42d3p7-0123456789abcdef@reply.forum.test';
        $this->tokens->shouldReceive('mintAddress')->once()->with(42, 3, 7)->andReturn($alias);

        $email = self::email();
        $email->replyTo('old@forum.test');

        $this->mutator()->handle(new MessageSending($email, [
            'user'            => self::user(),
            'blueprint'       => new SubjectBlueprint(self::post(7, 3)),
            'unsubscribeLink' => 'https://forum.test/unsubscribe/abc',
        ]));

        $headers = $email->getHeaders();

        self::assertSame([$alias], array_map(static fn (Address $a) => $a->getAddress(), $email->getReplyTo()));
        self::assertCount(1, iterator_to_array($headers->all('reply-to'), false));
        self::assertSame('auto-generated', $headers->get('Auto-Submitted')?->getBodyAsString());
        self::assertSame('All', $headers->get('X-Auto-Response-Suppress')?->getBodyAsString());
        self::assertSame('disallow', $headers->get('x-ms-reactions')?->getBodyAsString());
        self::assertSame('<https://forum.test/unsubscribe/abc>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('John mentioned you in "Topic" #7', $email->getSubject());
        self::assertSame([], $this->logger->errors());
    }

    public function test_existing_suppression_header_is_not_duplicated_and_subject_marker_is_idempotent(): void
    {
        $this->tokens->shouldReceive('mintAddress')->andReturn('reply+u42d3p7-0123456789abcdef@reply.forum.test');

        $email = self::email();
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-notified');
        $event = new MessageSending($email, ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(7, 3))]);

        $mutator = $this->mutator();
        $mutator->handle($event);
        $mutator->handle($event);

        self::assertCount(1, iterator_to_array($email->getHeaders()->all('auto-submitted'), false));
        self::assertSame('auto-notified', $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
        self::assertCount(1, iterator_to_array($email->getHeaders()->all('reply-to'), false));
        self::assertSame('John mentioned you in "Topic" #7', $email->getSubject());
    }

    public function test_post_mentioned_blueprint_targets_the_reply_post(): void
    {
        $this->tokens->shouldReceive('mintAddress')->once()->with(42, 3, 99)->andReturn('reply+u42d3p99-0123456789abcdef@reply.forum.test');

        $email = self::email('Someone replied to your post');
        $this->mutator()->handle(new MessageSending($email, [
            'user'      => self::user(),
            'blueprint' => new PostMentionedLikeBlueprint(self::post(7, 3), self::post(99, 3)),
        ]));

        self::assertSame('Someone replied to your post #99', $email->getSubject());
    }

    public function test_discussion_subject_mints_alias_without_post_and_keeps_subject(): void
    {
        $this->tokens->shouldReceive('mintAddress')->once()->with(42, 3, null)->andReturn('reply+u42d3-0123456789abcdef@reply.forum.test');

        $discussion = new Discussion();
        $discussion->id = 3;

        $email = self::email('New reply in "Topic"');
        $this->mutator()->handle(new MessageSending($email, ['user' => self::user(), 'blueprint' => new SubjectBlueprint($discussion)]));

        self::assertSame('reply+u42d3-0123456789abcdef@reply.forum.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('New reply in "Topic"', $email->getSubject());
    }

    public function test_private_discussion_gets_no_reply_to(): void
    {
        $this->tokens->shouldNotReceive('mintAddress');

        $email = self::email();
        $this->mutator()->handle(new MessageSending($email, ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(8, 4))]));

        self::assertSame([], $email->getReplyTo());
        self::assertFalse($email->getHeaders()->has('Auto-Submitted'));
    }

    public function test_unconfigured_mailbox_leaves_message_untouched(): void
    {
        $this->tokens->shouldReceive('mintAddress')->andReturnNull();

        $email = self::email();
        $email->replyTo('keep@forum.test');
        $this->mutator()->handle(new MessageSending($email, ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(7, 3))]));

        self::assertSame('keep@forum.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('John mentioned you in "Topic"', $email->getSubject());
    }

    public function test_non_notification_mail_only_gets_core_mutation(): void
    {
        $this->tokens->shouldNotReceive('mintAddress');

        $email = self::email('Confirm your email');
        $this->mutator()->handle(new MessageSending($email, ['unsubscribeLink' => 'https://forum.test/u/1']));
        $this->mutator()->handle(new MessageSending(self::email(), ['user' => ['id' => 1], 'blueprint' => 'string']));

        self::assertSame('<https://forum.test/u/1>', $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame([], $email->getReplyTo());
    }

    public function test_token_failure_is_reported_and_never_breaks_sending(): void
    {
        $this->tokens->shouldReceive('mintAddress')->andThrow(new \RuntimeException('boom'));

        $email = self::email();
        $result = $this->mutator()->handle(new MessageSending($email, ['user' => self::user(), 'blueprint' => new SubjectBlueprint(self::post(7, 3))]));

        self::assertNull($result);
        self::assertSame([], $email->getReplyTo());
        self::assertNotEmpty($this->logger->errors());
    }
}
