<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class EventDiscussionMigrationTest extends TestCase
{
    private const CONNECTION = 'pass07_events_sqlite';

    private const MIGRATION = '2026_11_22_000003_link_community_events_to_threads.php';

    public function test_populated_sqlite_schema_preserves_legacy_events_and_protects_linked_history(): void
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
            $db->table('users')->insert(['id' => 11, 'name' => 'Owner', 'email' => 'owner@example.invalid', 'password' => 'fixture']);
            $db->table('location_sources')->insert(['id' => 21, 'name' => 'Local', 'license' => 'Fixture']);
            $db->table('locations')->insert(['id' => 31, 'source_id' => 21, 'name' => 'Brandon', 'slug' => 'brandon', 'type' => 'city']);
            $db->table('community_threads')->insert([
                'id' => 41, 'author_id' => 11, 'location_id' => 31, 'title' => 'Original source',
                'body' => 'Original discussion details', 'category' => 'general', 'status' => 'approved',
            ]);
            $db->table('community_replies')->insert([
                'id' => 42, 'thread_id' => 41, 'author_id' => 11, 'body' => 'Previously published reply', 'status' => 'approved',
            ]);
            $event = [
                'author_id' => 11, 'location_id' => 31, 'title' => 'Original event', 'body' => 'Original public details',
                'category' => 'community', 'organizer' => 'Community association', 'venue' => 'Town hall',
                'event_url' => 'https://example.invalid/event', 'starts_at' => '2026-01-01 20:00:00',
            ];
            $db->table('community_events')->insert($event + ['id' => 51, 'status' => 'approved']);
            $db->table('community_events')->insert($event + [
                'id' => 52, 'status' => 'cancelled', 'cancelled_at' => '2026-01-01 19:00:00', 'cancellation_note' => 'Snow',
            ]);
            $legacyRows = $db->table('community_events')->orderBy('id')->get()->toArray();
            $legacyKeys = $schema->getForeignKeys('community_events');
            $legacyIndexes = $schema->getIndexes('community_events');

            $this->assertFileExists($migration);
            $this->assertSame(0, Artisan::call('migrate', $migrate + ['--path' => [$migration]]), Artisan::output());
            $this->assertTrue($schema->hasColumn('community_events', 'thread_id'));
            $this->assertSame([null, null], $db->table('community_events')->orderBy('id')->pluck('thread_id')->all());
            $this->assertEquals($legacyRows, $db->table('community_events')->orderBy('id')->get(array_keys((array) $legacyRows[0]))->all());

            $db->table('community_events')->insert($event + ['id' => 53, 'thread_id' => 41]);
            foreach ([
                fn () => $db->table('community_events')->insert($event + ['id' => 54, 'thread_id' => 41]),
                fn () => $db->table('community_events')->insert($event + ['id' => 55, 'thread_id' => 999]),
                fn () => $db->table('community_threads')->where('id', 41)->delete(),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected the database to reject a duplicate or broken event link.');
                } catch (QueryException $error) {
                    $this->assertSame('23000', $error->getCode());
                }
            }

            $rollback = $migrate + ['--path' => [$migration], '--step' => 1];
            try {
                Artisan::call('migrate:rollback', $rollback);
                $this->fail('A rollback that removes event links must be refused.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Linked events require this schema', $error->getMessage());
            }
            $this->assertEquals(41, $db->table('community_events')->where('id', 53)->value('thread_id'));
            $this->assertSame('Previously published reply', $db->table('community_replies')->where('id', 42)->value('body'));

            $db->table('community_events')->where('id', 53)->delete();
            $this->assertSame(0, Artisan::call('migrate:rollback', $rollback), Artisan::output());
            $this->assertFalse($schema->hasColumn('community_events', 'thread_id'));
            $this->assertEquals($legacyRows, $db->table('community_events')->orderBy('id')->get()->all());
            $this->assertEqualsCanonicalizing($legacyKeys, $schema->getForeignKeys('community_events'));
            $this->assertEqualsCanonicalizing($legacyIndexes, $schema->getIndexes('community_events'));
            $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
        } finally {
            DB::purge(self::CONNECTION);
        }
    }
}
