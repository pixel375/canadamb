<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventEditingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_event_corrections_preserve_identity_and_require_fresh_moderation(): void
    {
        $this->travelTo('2026-02-25 12:00:00');
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $event = CommunityEvent::create(['author_id' => $owner->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Community meetup', 'body' => 'A local community meeting.', 'category' => 'community',
            'organizer' => 'Local club', 'venue' => 'Club hall', 'starts_at' => '2026-03-01 16:00:00']);
        $event->forceFill(['status' => 'approved'])->save();
        $path = 'https://brandonmb.ca/account/events/'.$event->id;
        $review = 'https://canadamb.ca/admin/community/events/'.$event->id;
        $public = 'https://brandonmb.ca/events/'.$event->id;
        $data = ['title' => $event->title, 'body' => $event->body, 'category' => $event->category,
            'organizer' => $event->organizer, 'venue' => $event->venue, 'starts_at' => '2026-03-01T10:00', 'version' => $event->contentVersion()];
        $this->actingAs(User::factory()->create())->get($path.'/edit')->assertNotFound();
        $this->actingAs($owner)->get($path.'/edit')->assertOk()->assertSee('Community meetup');
        $this->put($path, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $event->fresh()->status);
        $changed = array_replace($data, ['venue' => 'New venue']);
        $this->put($path, $changed)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $event->fresh()->status);
        $this->get($public)->assertNotFound();
        $this->put($path, $changed)->assertSessionHasErrors('version');
        $this->actingAs($admin)->post($review, ['action' => 'approve', 'version' => $data['version']])->assertSessionHasErrors('version');
        $this->post($review, ['action' => 'approve', 'version' => $event->fresh()->contentVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($public)->assertOk()->assertSee('New venue');
        $this->assertDatabaseCount('community_events', 1);
        $this->actingAs($owner)->post($path.'/cancel', ['version' => $data['version']])->assertSessionHasErrors('version');
        $this->assertSame('approved', $event->fresh()->status);
        $past = $event->replicate();
        $past->forceFill(['title' => 'Past community meetup', 'starts_at' => '2026-02-01 16:00:00'])->save();
        $this->get('https://brandonmb.ca/account/events')->assertOk()->assertSee('Past community meetup');
        $this->get('https://brandonmb.ca/account/events?sort=upcoming')->assertOk()->assertSee('Community meetup')->assertDontSee('Past community meetup');
    }
}
