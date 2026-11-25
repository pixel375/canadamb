<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentReportsTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_reports_are_scoped_private_deduplicated_and_independently_resolved(): void
    {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'general', 'title' => 'Reported public discussion', 'body' => 'A public discussion body.',
        ]);
        $thread->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
        $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => 'A pending reply.']);
        $other = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => Location::where('slug', 'elkhorn')->sole()->id,
            'category' => 'general', 'title' => 'Other community thread', 'body' => 'A different community body.',
        ]);
        $other->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
        $url = 'https://brandonmb.ca/account/report/discussion/'.$thread->id;
        $data = ['reason' => 'spam', 'explanation' => 'This is a private report explanation.'];
        $this->actingAs($reporter)->get($url)->assertNotFound();
        config(['network.content_reports' => true]);
        $this->get($url)->assertOk()->assertSee('Reported public discussion');
        $this->post('https://brandonmb.ca/account/report/discussion/'.$other->id, $data)->assertNotFound();
        $this->get('https://brandonmb.ca/account/report/reply/'.$reply->id)->assertNotFound();
        $this->post($url, $data)->assertRedirect('/account/content-reports')->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect();
        $this->assertDatabaseCount('content_reports', 1);
        $this->get($url)->assertOk()->assertSee('already reported');
        $history = 'https://brandonmb.ca/account/content-reports';
        $this->get($history)->assertOk()->assertSee($data['explanation']);
        $this->actingAs($author)->get($history)->assertOk()->assertDontSee($data['explanation']);
        $this->get('https://canadamb.ca/admin/content-reports')->assertForbidden();
        $this->actingAs($admin)->get('https://canadamb.ca/admin/content-reports')->assertOk()->assertSee($data['explanation'])->assertSee($thread->title);
        $id = DB::table('content_reports')->value('id');
        $this->post('https://canadamb.ca/admin/content-reports/'.$id, ['note' => 'Internal review completed.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $thread->fresh()->status);
        $this->actingAs($reporter)->get($history)->assertOk()->assertSee('Review completed')->assertDontSee('Internal review completed.');
    }
}
