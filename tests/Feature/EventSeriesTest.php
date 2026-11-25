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

    public function test_bounded_repeat_preserves_local_time_and_exports_only_approved_occurrences(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 1)->startOfDay());
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $url = 'https://brandonmb.ca/events';
        $input = ['title' => 'Weekly club, meeting', 'body' => "Agenda\nBEGIN:VEVENT", 'category' => 'community',
            'venue' => 'Club hall', 'organizer' => 'Local club', 'starts_at' => '2027-03-07T10:00',
            'ends_at' => '2027-03-07T11:00', 'repeat' => 'weekly', 'occurrences' => 3];
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
        $this->get($url.'?month=2027-03')->assertOk()->assertSee('Day-by-day agenda')->assertSee('Weekly club, meeting');
        $this->actingAs($member)->post('https://brandonmb.ca/account/events/'.$events[0]->id.'/cancel')->assertRedirect();
        $this->get($file)->assertNotFound();
        $this->post($url, array_replace($input, ['title' => 'Invalid DST series', 'starts_at' => '2027-03-07T02:30', 'ends_at' => '2027-03-07T04:00']))->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('community_events', 3);
    }
}
