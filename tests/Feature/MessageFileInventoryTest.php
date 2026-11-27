<?php

namespace Tests\Feature;

use App\Support\MessageAttachments;
use App\Support\MessageFileInventory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageFileInventoryTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_inventory_is_bounded_read_only_and_excludes_recent_files(): void
    {
        Storage::fake(MessageAttachments::DISK);
        $disk = Storage::disk(MessageAttachments::DISK);
        $old = 'attachments/11111111-1111-1111-1111-111111111111.webp';
        $recent = 'attachments/22222222-2222-2222-2222-222222222222.webp';
        $disk->put($old, 'fixture');
        $disk->put($recent, 'fixture');
        touch($disk->path($old), now()->subDays(3)->timestamp);
        $inventory = app(MessageFileInventory::class);
        $result = $inventory->inspect(10);
        $this->assertSame(2, $result['files_examined']);
        $this->assertSame(1, $result['old_unreferenced_files']);
        $this->assertTrue($inventory->inspect(1)['files_truncated']);
        $disk->assertExists([$old, $recent]);
        $directory = $disk->path('attachments');
        $moved = $disk->path('attachments-original');
        rename($directory, $moved);
        symlink($moved, $directory);
        try {
            $result = $inventory->inspect(10);
            $this->assertTrue($result['directory_symlink']);
            $this->assertSame(0, $result['files_examined']);
        } finally {
            unlink($directory);
            rename($moved, $directory);
        }
    }
}
