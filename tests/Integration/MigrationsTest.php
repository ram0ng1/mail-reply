<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Database\InboundEmail;
use Ramon\MailReply\Tests\Support\Database;

/**
 * Executa as migrations reais contra um schema mínimo de `users`/`posts`
 * em cada engine disponível (SQLite sempre; MySQL/MariaDB/PostgreSQL via
 * env vars MAIL_REPLY_TEST_*), validando DDL e backfill cross-engine.
 */
final class MigrationsTest extends TestCase
{
    private const TABLES = ['mail_reply_inbound', 'mail_reply_posts', 'mail_reply_tokens', 'posts', 'users'];

    private ?Connection $db = null;
    private ?Builder $schema = null;

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return Database::engines();
    }

    private function boot(string $engine, bool $legacyColumn = true): void
    {
        $this->db = Database::use($engine);
        if ($this->db === null) {
            self::markTestSkipped("Database engine '$engine' is not available/configured.");
        }

        $this->schema = $this->db->getSchemaBuilder();
        $this->dropAll();

        $this->schema->create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('username', 100);
        });
        $this->schema->create('posts', function (Blueprint $t) use ($legacyColumn) {
            $t->increments('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->text('content')->nullable();
            if ($legacyColumn) {
                $t->boolean('via_mail_reply')->default(false);
            }
        });

        $this->db->table('users')->insert([['id' => 1, 'username' => 'ana'], ['id' => 2, 'username' => 'bia']]);

        $rows = [
            ['id' => 1, 'user_id' => 1, 'content' => 'a'],
            ['id' => 2, 'user_id' => 1, 'content' => 'b'],
            ['id' => 3, 'user_id' => 2, 'content' => 'c'],
            ['id' => 4, 'user_id' => 2, 'content' => 'd'],
        ];
        if ($legacyColumn) {
            foreach ($rows as $i => $row) {
                $rows[$i]['via_mail_reply'] = in_array($row['id'], [1, 3], true);
            }
        }
        $this->db->table('posts')->insert($rows);
    }

    private function dropAll(): void
    {
        foreach (self::TABLES as $table) {
            $this->schema?->dropIfExists($table);
        }
    }

    protected function tearDown(): void
    {
        $this->dropAll();
        $this->db = null;
        $this->schema = null;
    }

    /**
     * @return array{up: callable, down: callable}
     */
    private static function migration(string $name): array
    {
        return require dirname(__DIR__, 2).'/migrations/'.$name.'.php';
    }

    private function migrate(string $name, string $direction = 'up'): void
    {
        (self::migration($name)[$direction])($this->schema);
    }

    /**
     * @return list<int>
     */
    private function companionIds(): array
    {
        return $this->db->table('mail_reply_posts')->orderBy('post_id')->pluck('post_id')->map(fn ($id) => (int) $id)->all();
    }

    #[DataProvider('engines')]
    public function test_retired_add_column_migration_is_a_no_op(string $engine): void
    {
        $this->boot($engine, false);

        $this->migrate('2026_04_21_000001_add_via_mail_reply_to_posts');

        self::assertFalse($this->schema->hasColumn('posts', 'via_mail_reply'));

        $this->migrate('2026_04_21_000001_add_via_mail_reply_to_posts', 'down');

        self::assertTrue($this->schema->hasTable('posts'));
    }

    #[DataProvider('engines')]
    public function test_drop_tokens_table_is_idempotent_and_reversible(string $engine): void
    {
        $this->boot($engine);
        $name = '2026_04_21_000002_drop_mail_reply_tokens_table';

        $this->migrate($name);
        $this->migrate($name, 'down');
        self::assertTrue($this->schema->hasTable('mail_reply_tokens'));
        $this->migrate($name, 'down');

        $this->migrate($name);
        $this->migrate($name);
        self::assertFalse($this->schema->hasTable('mail_reply_tokens'));
    }

    #[DataProvider('engines')]
    public function test_backfill_copies_true_rows_and_drops_legacy_column(string $engine): void
    {
        $this->boot($engine);

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');

        self::assertSame([1, 3], $this->companionIds());
        self::assertFalse($this->schema->hasColumn('posts', 'via_mail_reply'));
        self::assertSame(4, $this->db->table('posts')->count());
    }

    #[DataProvider('engines')]
    public function test_backfill_up_twice_is_idempotent(string $engine): void
    {
        $this->boot($engine);

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');
        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');

        self::assertSame([1, 3], $this->companionIds());
    }

    #[DataProvider('engines')]
    public function test_backfill_skips_rows_already_present_in_companion_table(string $engine): void
    {
        $this->boot($engine);

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->db->table('mail_reply_posts')->insert(['post_id' => 1]);

        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');

        self::assertSame([1, 3], $this->companionIds());
    }

    #[DataProvider('engines')]
    public function test_down_restores_column_with_reverse_backfill_and_up_again_does_not_duplicate(string $engine): void
    {
        $this->boot($engine);
        $backfill = '2026_05_17_000002_backfill_and_drop_via_mail_reply_column';

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate($backfill);
        $this->migrate($backfill, 'down');

        self::assertTrue($this->schema->hasColumn('posts', 'via_mail_reply'));
        $flagged = $this->db->table('posts')->where('via_mail_reply', true)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        self::assertSame([1, 3], $flagged);

        $this->migrate($backfill, 'down');
        $this->migrate($backfill);

        self::assertSame([1, 3], $this->companionIds());
        self::assertFalse($this->schema->hasColumn('posts', 'via_mail_reply'));
    }

    #[DataProvider('engines')]
    public function test_backfill_on_fresh_install_without_legacy_column_is_a_no_op(string $engine): void
    {
        $this->boot($engine, false);

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');

        self::assertSame([], $this->companionIds());
    }

    #[DataProvider('engines')]
    public function test_companion_rows_cascade_when_post_is_deleted(string $engine): void
    {
        $this->boot($engine);

        $this->migrate('2026_05_17_000001_create_mail_reply_posts_table');
        $this->migrate('2026_05_17_000002_backfill_and_drop_via_mail_reply_column');
        $this->db->table('posts')->where('id', 3)->delete();

        self::assertSame([1], $this->companionIds());
    }

    #[DataProvider('engines')]
    public function test_inbound_table_enforces_unique_message_hash(string $engine): void
    {
        $this->boot($engine);
        $name = '2026_10_16_000001_create_mail_reply_inbound_table';

        $this->migrate($name);
        $this->migrate($name);

        $row = [
            'message_hash' => hash('sha256', 'mid|a@b'),
            'user_id'      => 1,
            'status'       => 'processed',
            'created_at'   => '2026-10-16 10:00:00',
        ];
        $this->db->table('mail_reply_inbound')->insert($row);

        try {
            $this->db->table('mail_reply_inbound')->insert(['status' => 'ignored'] + $row);
            self::fail('Duplicate message_hash must violate the unique index.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $this->db->table('mail_reply_inbound')->count());
        }
    }

    #[DataProvider('engines')]
    public function test_inbound_foreign_keys_null_out_on_delete(string $engine): void
    {
        $this->boot($engine);
        $this->migrate('2026_10_16_000001_create_mail_reply_inbound_table');

        $this->db->table('mail_reply_inbound')->insert([
            'message_hash' => str_repeat('a', 64),
            'user_id'      => 2,
            'discussion_id' => 10,
            'post_id'      => 4,
            'status'       => 'processed',
            'created_at'   => '2026-10-16 10:00:00',
        ]);

        $this->db->table('posts')->where('id', 4)->delete();
        $this->db->table('users')->where('id', 2)->delete();

        $row = $this->db->table('mail_reply_inbound')->first();
        self::assertNull($row->post_id);
        self::assertNull($row->user_id);
        self::assertSame(10, (int) $row->discussion_id);
    }

    #[DataProvider('engines')]
    public function test_inbound_model_round_trips_and_down_drops_table(string $engine): void
    {
        $this->boot($engine);
        $name = '2026_10_16_000001_create_mail_reply_inbound_table';
        $this->migrate($name);

        $record = new InboundEmail();
        $record->message_hash = hash('sha256', 'mid|x@y');
        $record->user_id = 1;
        $record->status = InboundEmail::STATUS_REJECTED;
        $record->reason = 'auto_submitted';
        $record->created_at = Carbon::parse('2026-10-16 12:00:00');
        $record->save();

        $fresh = InboundEmail::query()->where('message_hash', hash('sha256', 'mid|x@y'))->first();
        self::assertNotNull($fresh);
        self::assertSame(1, $fresh->user_id);
        self::assertNull($fresh->post_id);
        self::assertSame('auto_submitted', $fresh->reason);
        self::assertSame('2026-10-16 12:00:00', $fresh->created_at->format('Y-m-d H:i:s'));

        $this->migrate($name, 'down');
        self::assertFalse($this->schema->hasTable('mail_reply_inbound'));
    }
}
