<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_brandon_discussions_and_replies_share_moderated_visibility(): void
    {
        $input = ['title' => 'Neighbourhood garden planning', 'body' => 'We are planning a shared neighbourhood garden.', 'category' => 'general'];
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $this->actingAs($author)->post('https://brandonmb.ca/community', $input + ['location_id' => $winnipeg])->assertSessionHasErrors('location_id');
        $this->post('https://brandonmb.ca/community', $input)->assertSessionHasNoErrors()->assertRedirect();
        $thread = CommunityThread::sole();
        $this->assertSame('brandon', $thread->location->slug);
        $city = "https://brandonmb.ca/community/$thread->id";
        $main = "https://canadamb.ca/community/$thread->id";

        $this->get($city)->assertNotFound();
        $this->post("https://canadamb.ca/admin/community/threads/$thread->id", ['action' => 'approve'])->assertForbidden();
        $this->actingAs($moderator)->post("https://canadamb.ca/admin/community/threads/$thread->id", ['action' => 'approve'])->assertSessionHasErrors('version');
        $this->post("https://canadamb.ca/admin/community/threads/$thread->id", ['action' => 'approve', 'version' => $thread->fresh()->contentVersion()])->assertRedirect();
        $this->get($main)->assertOk()->assertSee($input['title'])->assertSee('<link rel="canonical" href="'.$city.'">', false);
        $this->get('https://canadamb.ca/community')->assertSee($input['title']);
        $this->get('https://canadamb.ca/')->assertSee($input['title']);

        $this->actingAs($author)->post("$city/follow")->assertRedirect();
        $this->actingAs($reader)->post("$city/replies", ['body' => 'I can donate some vegetable seeds.'])->assertSessionHasNoErrors();
        $this->get($city)->assertDontSee('I can donate some vegetable seeds.');
        $reply = CommunityReply::sole();
        $this->actingAs($moderator)->post("https://canadamb.ca/admin/community/replies/$reply->id", ['action' => 'approve', 'version' => $reply->contentVersion()])->assertRedirect();
        $this->get($city)->assertSee('I can donate some vegetable seeds.');
        $this->get($main)->assertOk()->assertSee('I can donate some vegetable seeds.');
        $this->actingAs($author)->get('https://canadamb.ca/account/community-notifications')->assertSee('Neighbourhood garden planning');
        $this->actingAs($moderator)->post("https://canadamb.ca/admin/community/replies/$reply->id", [
            'action' => 'reject', 'reason' => 'Reply removed during second review.', 'version' => $reply->fresh()->contentVersion(),
        ])->assertRedirect();
        $this->get($city)->assertOk()->assertDontSee('I can donate some vegetable seeds.');
        $queue = 'https://canadamb.ca/admin/community';
        $this->get($queue.'?status=rejected&location='.$thread->location_id)->assertOk()
            ->assertSee('I can donate some vegetable seeds.')->assertSee('Historical record')
            ->assertDontSee('action="/admin/community/replies/'.$reply->id.'"', false);
        $this->get($queue.'?status=rejected&location='.$winnipeg)->assertOk()->assertDontSee('I can donate some vegetable seeds.');
        $this->actingAs($moderator)->post("https://canadamb.ca/admin/community/threads/$thread->id", ['action' => 'lock', 'version' => $thread->fresh()->contentVersion()])->assertRedirect();
        $this->actingAs($reader)->post("$city/replies", ['body' => ''])->assertStatus(409);
    }

    public function test_events_validate_manitoba_local_time_and_scope_before_publication(): void
    {
        // Keep the gap test on Manitoba's last spring clock change.
        $this->travelTo('2026-03-01 12:00:00');
        $input = [
            'title' => 'Spring seed exchange', 'body' => 'Bring seeds to trade with your neighbours.',
            'starts_at' => '2026-03-08T02:30', 'ends_at' => '2026-03-08T04:30',
            'category' => 'community', 'venue' => 'Community hall', 'organizer' => 'Garden club',
        ];
        $organizer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $this->actingAs($organizer)->post('https://brandonmb.ca/events', $input)->assertSessionHasErrors('starts_at');
        $input['starts_at'] = '2026-03-08T03:30';
        $this->post('https://brandonmb.ca/events', $input)->assertSessionHasNoErrors()->assertRedirect();
        $event = CommunityEvent::sole();
        $this->assertSame('2026-03-08 08:30:00', $event->getRawOriginal('starts_at'));
        $this->get('https://brandonmb.ca/events')->assertDontSee($input['title']);
        $this->actingAs($admin)->post("https://canadamb.ca/admin/community/events/$event->id", ['action' => 'approve'])->assertSessionHasErrors('version');
        $this->post("https://canadamb.ca/admin/community/events/$event->id", ['action' => 'approve', 'version' => $event->fresh()->contentVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/events?month=2026-03')->assertOk()->assertSee($input['title']);
        $this->get('https://canadamb.ca/events')->assertSee($input['title']);
        $this->get('https://canadamb.ca/')->assertSee($input['title']);
        $this->get("https://canadamb.ca/events/$event->id")->assertOk()->assertSee($input['title'])->assertSee('3:30 am');
        $this->get("https://brandonmb.ca/events/$event->id")->assertSee('3:30 am');

        $this->actingAs(User::factory()->create())->post("https://brandonmb.ca/account/events/$event->id/cancel")->assertNotFound();
        $this->actingAs($organizer)->post("https://brandonmb.ca/account/events/$event->id/cancel", ['cancellation_note' => 'Organizer-only cancellation note', 'version' => $event->fresh()->contentVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/events')->assertDontSee($input['title']);
        $this->get("https://brandonmb.ca/events/$event->id")->assertSee('Cancelled')->assertDontSee('Organizer-only cancellation note');
        $this->actingAs($admin)->post("https://canadamb.ca/admin/community/events/$event->id", ['action' => 'reject', 'version' => $event->fresh()->contentVersion(), 'reason' => 'Event detail withdrawn.'])->assertRedirect();
        $this->get("https://brandonmb.ca/events/$event->id")->assertNotFound();
    }

    public function test_calendar_export_preserves_explicit_all_and_remembered_location(): void
    {
        $this->travelTo('2026-11-26 12:00:00');
        $owner = User::factory()->create();
        $events = [];
        foreach (['brandon', 'winnipeg'] as $slug) {
            $events[$slug] = CommunityEvent::create([
                'author_id' => $owner->id, 'location_id' => Location::where('slug', $slug)->sole()->id,
                'title' => ucfirst($slug).' calendar scope fixture', 'body' => 'A public community event.',
                'category' => 'community', 'venue' => 'Local hall', 'organizer' => 'Local club',
                'starts_at' => '2026-12-02 16:00:00',
            ]);
            $events[$slug]->forceFill(['status' => 'approved'])->save();
        }
        $base = 'https://canadamb.ca';
        $brandon = 'UID:event-'.$events['brandon']->id.'@canadamb.ca';
        $winnipeg = 'UID:event-'.$events['winnipeg']->id.'@canadamb.ca';
        $this->withCookie('canadamb_community', 'winnipeg')->get($base.'/events?location=')
            ->assertOk()->assertSee('href="/events/calendar?location="', false);
        $this->get($base.'/events/calendar?location=')->assertOk()->assertSee($brandon)->assertSee($winnipeg);
        $this->get($base.'/events/calendar')->assertOk()->assertSee($winnipeg)->assertDontSee($brandon);
        $this->get('https://brandonmb.ca/events/calendar?location=winnipeg')->assertOk()->assertSee($brandon)->assertDontSee($winnipeg);
    }

    public function test_an_event_that_overlaps_two_months_is_listed_on_its_later_local_day(): void
    {
        $this->travelTo('2027-03-15 12:00:00');
        $this->actingAs(User::factory()->create())->post('https://brandonmb.ca/events', [
            'title' => 'Overnight event', 'body' => 'A community event that continues into the next day.',
            'starts_at' => '2027-03-31T21:00', 'ends_at' => '2027-04-01T02:00',
            'category' => 'community', 'venue' => 'Hall', 'organizer' => 'Local club',
        ])->assertSessionHasNoErrors();
        $id = CommunityEvent::sole()->id;
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post("https://canadamb.ca/admin/community/events/$id", ['action' => 'approve', 'version' => CommunityEvent::findOrFail($id)->fresh()->contentVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/events?month=2027-04&day=2027-04-01')->assertOk()->assertSee('Overnight event');
        $this->get('https://brandonmb.ca/events?month=2027-04&day=2027-04-02')->assertOk()->assertDontSee('Overnight event');
    }
}
