<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_selected_rescheduling_preserves_wall_time_and_rejects_duplicates_atomically(): void
    {
        $this->travelTo('2026-02-25 12:00:00');
        config(['network.recurring_events' => true]);
        $member = User::factory()->create();
        $this->actingAs($member)->post('https://brandonmb.ca/events', ['title' => 'Club shift', 'body' => 'A recurring club meeting.', 'category' => 'community',
            'venue' => 'Hall', 'organizer' => 'Club', 'starts_at' => '2026-03-01T10:00', 'ends_at' => '2026-03-01T11:00', 'repeat' => 'weekly', 'occurrences' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $events = CommunityEvent::orderBy('starts_at')->get();
        $data = ['events' => [$events[0]->id, $events[1]->id], 'versions' => $events->mapWithKeys(fn ($item) => [$item->id => $item->contentVersion()])->all(), 'days' => 7, 'confirm_shift' => '1'];
        $url = 'https://brandonmb.ca/account/events/shift-selected';
        $this->post($url, $data)->assertSessionHasErrors('days');
        $this->assertSame('2026-03-01', $events[0]->fresh()->localStart()->toDateString());
        $this->post($url, array_replace($data, ['days' => 6]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-03-07 10:00', $events[0]->fresh()->localStart()->format('Y-m-d H:i'));
        $this->assertSame('2026-03-14 11:00', $events[1]->fresh()->localEnd()->format('Y-m-d H:i'));
        $this->assertSame('2026-03-15', $events[2]->fresh()->localStart()->toDateString());
        $this->post($url, array_replace($data, ['days' => 1]))->assertSessionHasErrors('version');
        $this->get('https://canadamb.ca/account/events?category=community&reference='.$events[0]->id.'&q=Club')->assertOk()->assertSee('1 event found');
        $this->get('https://canadamb.ca/account/events?category=arts')->assertOk()->assertDontSee('Club shift');
        $this->travelTo('2026-03-10 12:00:00');
        $this->get('https://brandonmb.ca/account/events?sort=past')->assertOk()->assertSee('1 event found');
        $this->get('https://brandonmb.ca/account/events?sort=upcoming')->assertOk()->assertSee('2 events found');
    }

    public function test_bounded_repeat_preserves_local_time_and_exports_only_approved_occurrences(): void
    {
        $this->travelTo('2026-02-25 12:00:00');
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $url = 'https://brandonmb.ca/events';
        $input = ['title' => 'Weekly club, meeting', 'body' => "Agenda\nBEGIN:VEVENT", 'category' => 'community',
            'venue' => 'Club hall', 'organizer' => 'Local club', 'starts_at' => '2026-03-01T10:00',
            'ends_at' => '2026-03-01T11:00', 'repeat' => 'weekly', 'occurrences' => 3];
        $this->actingAs($member)->post($url, $input)->assertNotFound();
        config(['network.recurring_events' => true]);
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $events = CommunityEvent::orderBy('starts_at')->get();
        $this->assertCount(3, $events);
        $this->assertSame(['10:00', '10:00', '10:00'], $events->map(fn ($event) => $event->localStart()->format('H:i'))->all());
        $this->assertNotSame($events[0]->localStart()->offset, $events[1]->localStart()->offset);
        $this->post($url, $input)->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('community_events', 3);
        $file = $url.'/'.$events[0]->id.'/calendar';
        $this->get($file)->assertNotFound();
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/events/'.$events[0]->id, ['action' => 'approve'])->assertRedirect();
        $this->get($file)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')
            ->assertSee('SUMMARY:Weekly club\\, meeting', false)->assertSee('DESCRIPTION:Agenda\\nBEGIN:VEVENT', false);
        $this->get($url.'?month=2026-03')->assertOk()->assertSee('Day-by-day agenda')->assertSee('Weekly club, meeting')->assertSee('Download matching events');
        $this->get($url.'/calendar?month=2026-03&q=Weekly')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')
            ->assertSee('UID:event-'.$events[0]->id.'@canadamb.ca')->assertDontSee('UID:event-'.$events[1]->id.'@canadamb.ca');
        $this->get($url.'/calendar?month=2026-04')->assertOk()->assertDontSee('BEGIN:VEVENT');
        $this->get($url.'/calendar?month=2026-03&day=2026-04-01')->assertUnprocessable();
        $this->actingAs($member)->post('https://brandonmb.ca/account/events/'.$events[0]->id.'/cancel')->assertRedirect();
        $this->get($file)->assertNotFound();
        $this->get($url.'/calendar?month=2026-03')->assertOk()->assertDontSee('BEGIN:VEVENT');
        $this->get('https://brandonmb.ca/events/new?reuse='.$events[1]->id)->assertRedirect('/community/new?type=event&reuse='.$events[1]->id);
        $this->get('https://brandonmb.ca/community/new?type=event&reuse='.$events[1]->id)->assertOk()->assertSee('Weekly club, meeting')->assertSee('choose new dates');
        $this->post($url, array_replace($input, ['title' => 'Invalid DST series', 'starts_at' => '2026-03-01T02:30', 'ends_at' => '2026-03-01T04:00']))->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('community_events', 3);
        $other = User::factory()->create();
        $this->actingAs($other)->get('https://brandonmb.ca/community/new?type=event&reuse='.$events[1]->id)->assertNotFound();
        $edit = 'https://brandonmb.ca/account/events/revise-selected';
        $editVersions = [$events[1]->id => $events[1]->fresh()->contentVersion(), $events[2]->id => $events[2]->fresh()->contentVersion()];
        $input = ['events' => [$events[1]->id, $events[2]->id], 'versions' => $editVersions, 'field' => 'venue', 'value' => 'New club hall', 'confirm_update' => '1'];
        $this->post($edit, $input)->assertNotFound();
        $this->actingAs($member)->get('https://brandonmb.ca/account/events')->assertOk()->assertSee('Update shared event details');
        $this->post($edit, array_replace($input, ['versions' => array_replace($editVersions, [$events[2]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('version');
        $this->assertSame('Club hall', $events[1]->fresh()->venue);
        $this->post($edit, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('New club hall', $events[1]->fresh()->venue);
        $this->assertSame('New club hall', $events[2]->fresh()->venue);
        $this->assertSame('Club hall', $events[0]->fresh()->venue);
        $this->assertSame($events[1]->starts_at, $events[1]->fresh()->starts_at);
        $this->actingAs($other);
        $batch = 'https://brandonmb.ca/account/events/cancel-selected';
        $selection = ['events' => [$events[1]->id, $events[2]->id], 'confirm_cancel' => '1'];
        $this->post($batch, $selection)->assertNotFound();
        $this->actingAs($member)->post($batch, ['events' => [$events[0]->id, $events[1]->id], 'confirm_cancel' => '1'])->assertConflict();
        $this->assertSame('pending', $events[1]->fresh()->status);
        $versions = [$events[1]->id => $events[1]->fresh()->contentVersion(), $events[2]->id => str_repeat('0', 64)];
        $this->post($batch, $selection + ['versions' => $versions])->assertSessionHasErrors('version');
        $this->assertSame('pending', $events[1]->fresh()->status);
        $this->assertSame('pending', $events[2]->fresh()->status);
        $versions[$events[2]->id] = $events[2]->fresh()->contentVersion();
        $this->post($batch, $selection + ['versions' => $versions])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $events[1]->fresh()->status);
        $this->assertSame('withdrawn', $events[2]->fresh()->status);
        $this->assertDatabaseCount('community_events', 3);
    }
}
