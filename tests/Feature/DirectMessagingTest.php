<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Support\DirectMessaging;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class DirectMessagingTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            Conversation::whereNotNull('direct_kind')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_member_and_official_threads_require_consent_and_participant_access(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $stranger = User::factory()->create();
        $base = 'https://canadamb.ca';
        $start = $base.'/account/messages/member/'.$recipient->id;
        $preferences = $base.'/account/messages/preferences';
        $message = ['body' => 'Private introduction fixture.'];
        $this->actingAs($sender)->post($start.'/member', $message)->assertNotFound();
        config(['network.extended_messaging' => true, 'network.direct_messaging' => true]);
        $this->get($start.'/member')->assertNotFound();
        $this->post($start.'/member', $message)->assertNotFound();
        $initial = DirectMessaging::version(DirectMessaging::preferences($recipient->id));
        $this->actingAs($recipient)->post($preferences, ['member_messages' => 1, 'official_messages' => 1, 'version' => $initial])->assertRedirect('/account/settings');
        $this->post($preferences, ['member_messages' => 0, 'official_messages' => 0, 'version' => $initial])->assertSessionHasErrors('version');
        $this->actingAs($sender)->get($base.'/users/'.$recipient->id)->assertOk()->assertSee('Message member')->assertDontSee('Start official conversation');
        $this->get($start.'/member')->assertOk()->assertSee('New direct conversation');
        $this->post($start.'/member', $message)->assertRedirect();
        $direct = Conversation::where('direct_kind', 'member')->sole();
        $path = '/account/messages/'.$direct->id;
        $this->post($start.'/member', $message)->assertRedirect($path);
        $this->assertDatabaseCount('messages', 1);
        $this->actingAs($recipient)->get('https://brandonmb.ca'.$path)->assertOk()->assertSee($message['body']);
        $this->post('https://brandonmb.ca'.$path, ['body' => 'Reply fixture.'])->assertRedirect();
        $this->get($base.'/account/messages/member/'.$sender->id.'/member')->assertRedirect($path);
        $this->actingAs($stranger)->get($base.$path)->assertNotFound();
        $this->postJson($base.$path.'/sync', ['after' => 0])->assertNotFound();
        $this->post($start.'/official', $message)->assertNotFound();
        $this->actingAs($admin)->get($base.'/users/'.$recipient->id)->assertOk()->assertSee('Start official conversation');
        $this->post($start.'/official', ['body' => 'Official private fixture.'])->assertRedirect();
        $official = Conversation::where('direct_kind', 'official')->sole();
        $officialPath = '/account/messages/'.$official->id;
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.official-started', 'actor_id' => $admin->id]);
        $this->get($base.$path)->assertNotFound();
        $this->actingAs($recipient)->get($base.'/account/messages?kind=official')->assertOk()->assertSee('Official administrator')->assertDontSee('Direct member conversation');
        $this->get($base.$officialPath)->assertOk()->assertSee('Started by a CanadaMB administrator');
        $this->post($base.$path.'/block')->assertRedirect();
        $this->actingAs($sender)->post($base.$path, $message)->assertForbidden();
        $this->actingAs($recipient)->delete($base.$path.'/block')->assertRedirect();
        $version = DirectMessaging::version(DirectMessaging::preferences($recipient->id));
        $this->post($preferences, ['member_messages' => 0, 'official_messages' => 1, 'version' => $version])->assertRedirect();
        $this->actingAs($sender)->post($base.$path, $message)->assertForbidden();
        $this->get($base.$path)->assertOk()->assertSee($message['body']);
        $admin->forceFill(['role' => 'member'])->save();
        $this->actingAs($recipient)->post($base.$officialPath, ['body' => 'Reply after role change.'])->assertForbidden();
        $this->get($base.$officialPath)->assertOk()->assertSee('Official private fixture.')->assertSee('Historical official conversation')->assertDontSee('Started by a CanadaMB administrator');
        $this->get($base.'/account/messages?kind=official')->assertOk()->assertSee('Former official conversation')->assertDontSee('class="message-thread-kind  official-message-label"', false);
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseCount('messages', 3);
    }
}
