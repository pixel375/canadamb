<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_only_super_admin_can_see_bounded_aggregate_notification_statistics(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();
        $thread = CommunityThread::create(['author_id' => $author->id, 'title' => 'Private aggregate fixture title', 'category' => 'general', 'body' => 'Aggregate notification test content.']);
        $thread->forceFill(['status' => 'approved'])->save();
        foreach (['approved', 'pending'] as $status) {
            $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => 'Private aggregate fixture reply.']);
            $reply->forceFill(['status' => $status])->save();
            DB::table('community_notifications')->insert(['user_id' => $member->id, 'reply_id' => $reply->id, 'created_at' => now()]);
        }
        $url = 'https://canadamb.ca/admin/notifications';
        $this->get($url)->assertRedirect();
        $this->actingAs($member)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get($url)->assertOk()
            ->assertViewHas('metrics', ['created' => 1, 'unread' => 1, 'recipients' => 1])
            ->assertDontSee($member->email)->assertDontSee('Private aggregate fixture title')->assertDontSee('Private aggregate fixture reply.');
        $this->get($url.'?days=999')->assertSessionHasErrors('days');
        $this->get($url.'?days=7')->assertOk()->assertViewHas('days', 7);
        $this->post($url)->assertStatus(405);
    }
}
