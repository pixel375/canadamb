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

    public function test_event_pagination_preserves_explicit_province_scope_and_validated_filters(): void
    {
        $this->travelTo('2026-02-25 12:00:00');
        $owner = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        for ($i = 1; $i <= 25; $i++) {
            $event = CommunityEvent::create(['author_id' => $owner->id, 'location_id' => $i === 25 ? $winnipeg : $brandon,
                'title' => 'Pagination event '.$i, 'body' => 'A local community meeting.', 'category' => 'community',
                'organizer' => 'Local club', 'venue' => 'Club hall', 'starts_at' => '2026-03-01 16:00:00']);
            $event->forceFill(['status' => 'approved'])->save();
        }

        $page = $this->withCookie('canadamb_community', 'brandon')
            ->get('https://canadamb.ca/events?location=&q=Pagination&extra=discard')->assertOk();
        $next = $page->viewData('items')->nextPageUrl();
        $this->assertNotNull($next);
        $this->assertStringContainsString('location=', $next);
        $this->assertStringContainsString('q=Pagination', $next);
        $this->assertStringNotContainsString('extra=', $next);
        $this->get($next)->assertOk()->assertSee('Pagination event 25');
        $this->get('https://brandonmb.ca/events?location=winnipeg&q=Pagination')->assertOk()->assertDontSee('Pagination event 25');
    }

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
        $this->post($path.'/cancel')->assertNotFound();
        $this->put($path, [])->assertNotFound();
        $this->actingAs($owner)->get($path.'/edit?return_filters%5Bq%5D=0&return_filters%5Bsort%5D=upcoming')->assertOk()->assertSee('Community meetup')
            ->assertSee('href="/account/events?q=0&amp;sort=upcoming"', false)
            ->assertSee('name="return_filters[q]" value="0"', false);
        $this->put($path, $data + ['return_filters' => ['q' => '0', 'sort' => 'upcoming']])->assertRedirect('/account/events?q=0&sort=upcoming')->assertSessionHasNoErrors();
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
        $this->post('https://brandonmb.ca/account/events/'.$past->id.'/cancel')->assertConflict();
        $this->put('https://brandonmb.ca/account/events/'.$past->id, [])->assertConflict();
    }
}
