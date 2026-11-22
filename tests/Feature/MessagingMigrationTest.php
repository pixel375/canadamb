<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MessagingMigrationTest extends TestCase
{
    private const CONNECTION = 'pass04_messaging_sqlite';

    private const MIGRATION = '2026_11_22_000001_expand_unified_messaging.php';

    public function test_populated_sqlite_upgrade_preserves_message_history_and_foreign_keys(): void
    {
        config(['database.connections.'.self::CONNECTION => array_replace(config('database.connections.sqlite'), [
            'database' => ':memory:', 'url' => null, 'foreign_key_constraints' => true,
        ])]);
        $db = DB::connection(self::CONNECTION);
        $schema = Schema::connection(self::CONNECTION);
        $migration = database_path('migrations/'.self::MIGRATION);
        $migrate = ['--database' => self::CONNECTION, '--realpath' => true, '--force' => true];

        try {
            $legacyMigrations = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn (string $path): bool => basename($path) < self::MIGRATION));
            $this->assertSame(0, Artisan::call('migrate', $migrate + ['--path' => $legacyMigrations]), Artisan::output());

            $db->table('users')->insert([
                ['id' => 11, 'name' => 'Buyer', 'email' => 'buyer@example.invalid', 'password' => 'fixture'],
                ['id' => 12, 'name' => 'Seller', 'email' => 'seller@example.invalid', 'password' => 'fixture'],
            ]);
            $db->table('location_sources')->insert(['id' => 21, 'name' => 'Local', 'license' => 'Fixture']);
            $db->table('locations')->insert(['id' => 31, 'source_id' => 21, 'name' => 'Brandon', 'slug' => 'brandon', 'type' => 'city']);
            $db->table('listing_categories')->insert(['slug' => 'other', 'name' => 'Other']);
            $db->table('listings')->insert([
                'id' => 41, 'user_id' => 12, 'location_id' => 31, 'category' => 'other', 'title' => 'Original item',
                'description' => 'Legacy listing', 'price_cents' => 1000, 'condition' => 'good',
            ]);
            $db->table('conversations')->insert([
                'id' => 51, 'listing_id' => 41, 'buyer_id' => 11, 'seller_id' => 12,
                'buyer_last_read_id' => 61, 'seller_last_read_id' => 0,
            ]);
            $db->table('messages')->insert([
                ['id' => 61, 'conversation_id' => 51, 'sender_id' => 11, 'body' => 'First message'],
                ['id' => 62, 'conversation_id' => 51, 'sender_id' => 12, 'body' => 'Unread reply'],
            ]);
            $db->table('member_settings')->insert(['user_id' => 11, 'notify_marketplace_messages' => false]);

            $legacyKeys = $schema->getForeignKeys('conversations');
            $legacyIndexes = $schema->getIndexes('conversations');
            $this->assertFileExists($migration);
            $this->assertSame(0, Artisan::call('migrate', $migrate + ['--path' => [$migration]]), Artisan::output());

            $conversation = $db->table('conversations')->find(51);
            $this->assertSame('marketplace', $conversation->context_type);
            $this->assertSame([41, 11, 12, 61, 0], [
                $conversation->listing_id, $conversation->buyer_id, $conversation->seller_id,
                $conversation->buyer_last_read_id, $conversation->seller_last_read_id,
            ]);
            $this->assertNull($conversation->business_location_id);
            $this->assertNull($conversation->job_posting_id);
            $this->assertNull($conversation->location_id);
            $this->assertSame([61 => 'First message', 62 => 'Unread reply'], $db->table('messages')->orderBy('id')->pluck('body', 'id')->all());
            $this->assertSame([null, null], $db->table('messages')->orderBy('id')->pluck('idempotency_key')->all());
            $settings = $db->table('member_settings')->where('user_id', 11)->first();
            $this->assertSame([0, 1, 1], [$settings->notify_marketplace_messages, $settings->notify_business_messages, $settings->notify_job_messages]);
            foreach ($legacyKeys as $key) {
                $this->assertContains($key, $schema->getForeignKeys('conversations'));
            }
            foreach ($legacyIndexes as $index) {
                $this->assertContains($index, $schema->getIndexes('conversations'));
            }
            $this->assertTrue($schema->hasIndex('conversations', ['business_location_id', 'buyer_id', 'seller_id'], 'unique'));
            $this->assertTrue($schema->hasIndex('conversations', ['job_posting_id', 'buyer_id', 'seller_id'], 'unique'));
            $this->assertEquals(1, $db->scalar('PRAGMA foreign_keys'));
            $this->assertSame([], $db->select('PRAGMA foreign_key_check'));

            $db->table('conversation_reports')->insert(['conversation_id' => 51, 'reporter_id' => 11, 'message_id' => 62, 'reason' => 'Fixture']);
            $rollback = $migrate + ['--path' => [$migration], '--step' => 1];
            try {
                Artisan::call('migrate:rollback', $rollback);
                $this->fail('A rollback that removes existing reports must be refused.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Messaging data requires this schema', $error->getMessage());
            }
            $this->assertTrue($schema->hasTable('conversation_reports'));
            $db->table('conversation_reports')->delete();

            $this->assertSame(0, Artisan::call('migrate:rollback', $rollback), Artisan::output());
            $this->assertFalse($schema->hasColumn('conversations', 'context_type'));
            $this->assertSame([61 => 'First message', 62 => 'Unread reply'], $db->table('messages')->orderBy('id')->pluck('body', 'id')->all());
            $this->assertEqualsCanonicalizing($legacyKeys, $schema->getForeignKeys('conversations'));
            $this->assertEqualsCanonicalizing($legacyIndexes, $schema->getIndexes('conversations'));
            $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
        } finally {
            DB::purge(self::CONNECTION);
        }
    }
}
