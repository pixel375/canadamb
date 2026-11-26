<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\QueueInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_queue_inventory_is_read_only_and_never_includes_payloads(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        $now = now()->timestamp;
        foreach ([[$now - 120, null], [$now + 120, null], [$now - 120, $now - 100], [$now - 30, $now - 20]] as [$available, $reserved]) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'PRIVATE_PAYLOAD_FIXTURE', 'attempts' => 0,
                'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => $now - 120]);
        }
        $result = app(QueueInventory::class)->inspect();
        $this->assertSame(1, $result['ready']);
        $this->assertSame(1, $result['delayed']);
        $this->assertSame(2, $result['reserved']);
        $this->assertSame(1, $result['retryable_reservations']);
        $this->assertGreaterThanOrEqual(120, $result['oldest_ready_seconds']);
        $this->assertStringNotContainsString('PRIVATE_PAYLOAD_FIXTURE', json_encode($result, JSON_THROW_ON_ERROR));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('https://canadamb.ca/admin/readiness')
            ->assertOk()->assertSee('Queue observations')->assertDontSee('PRIVATE_PAYLOAD_FIXTURE');
        $this->assertDatabaseCount('jobs', 4);
        config(['queue.default' => 'sync']);
        $this->assertFalse(app(QueueInventory::class)->inspect()['database_queue']);
    }
}
