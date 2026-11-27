<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\User;
use App\Support\EventDiscussions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventSeriesIdentityTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            DB::table('event_series_occurrences')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_series_identity_is_private_persistent_and_bulk_title_changes_are_atomic(): void
    {
        $this->travelTo('2026-11-26 12:00:00');
        config(['network.recurring_events' => true, 'network.event_series_identity' => true, 'network.event_discussions' => true]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $base = 'https://brandonmb.ca';
        $input = ['title' => 'Weekly club fixture', 'body' => 'A recurring community club meeting.',
            'category' => 'community', 'venue' => 'Hall', 'organizer' => 'Local club',
            'starts_at' => '2026-12-02T10:00', 'ends_at' => '2026-12-02T11:00', 'repeat' => 'weekly', 'occurrences' => 3];
        $this->actingAs($member)->post($base.'/events', $input)->assertRedirect()->assertSessionHasNoErrors();
        $events = CommunityEvent::orderBy('starts_at')->get();
        $series = DB::table('event_series_occurrences')->orderBy('position')->get();
        $this->assertCount(3, $series);
        $this->assertCount(1, $series->pluck('series_key')->unique());
        $this->assertSame([1, 2, 3], $series->pluck('position')->map(fn ($position) => (int) $position)->all());
        $key = $series[0]->series_key;
        $path = '/account/events?series='.$key;
        $this->get($base.$path)->assertOk()->assertSee('3 events found')->assertSee('Series occurrence 1 of 3');
        $this->get('https://canadamb.ca'.$path)->assertOk()->assertSee('3 events found');
        $this->actingAs($other)->get($base.$path)->assertNotFound();
        $event = $events[0];
        $thread = EventDiscussions::linkedThread($event);
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/events/'.$event->id, ['action' => 'approve'])->assertRedirect();
        $this->post('https://canadamb.ca/admin/community/threads/'.$thread->id, ['action' => 'approve', 'version' => $thread->contentVersion()])->assertRedirect();
        $this->get($base.'/events/'.$event->id.'/calendar')->assertOk()->assertDontSee($key);
        $seriesFile = '/events/'.$event->id.'/series-calendar';
        $this->get($base.$seriesFile)->assertNotFound();
        $this->post('https://canadamb.ca/admin/community/events/'.$events[1]->id, ['action' => 'approve'])->assertRedirect();
        foreach ([$base, 'https://canadamb.ca'] as $host) {
            $this->get($host.'/events/'.$event->id)->assertOk()->assertSee('Download upcoming published series');
            $this->get($host.$seriesFile)->assertOk()->assertSee('UID:event-'.$event->id.'@canadamb.ca')
                ->assertSee('UID:event-'.$events[1]->id.'@canadamb.ca')->assertDontSee('UID:event-'.$events[2]->id.'@canadamb.ca')->assertDontSee($key);
        }
        $this->actingAs($member)->post($base.'/events', array_replace($input, ['title' => 'Unrelated event', 'repeat' => 'none']))->assertRedirect()->assertSessionHasNoErrors();
        $data = ['events' => $events->modelKeys(), 'versions' => $events->mapWithKeys(fn ($item) => [$item->id => $item->fresh()->contentVersion()])->all(),
            'thread_versions' => $events->mapWithKeys(fn ($item) => [$item->id => EventDiscussions::linkedThread($item)->contentVersion()])->all(),
            'field' => 'title', 'value' => 'Unrelated event', 'confirm_update' => '1'];
        $url = $base.'/account/events/revise-selected';
        $this->post($url, $data)->assertSessionHasErrors('value');
        $data['value'] = 'Renamed weekly club';
        $this->post($url, array_replace($data, ['thread_versions' => []]))->assertSessionHasErrors('thread_versions');
        $this->assertSame('Weekly club fixture', $event->fresh()->title);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Renamed weekly club', $event->fresh()->title);
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('Renamed weekly club', $thread->fresh()->title);
        $this->assertSame('pending', $thread->fresh()->status);
        $this->get($base.'/events/'.$event->id.'/calendar')->assertNotFound();
        $this->get($base.$seriesFile)->assertNotFound();
        $this->post($url, $data)->assertSessionHasErrors('version');
        $this->assertSame($key, DB::table('event_series_occurrences')->where('event_id', $event->id)->value('series_key'));
        $this->post($base.'/account/events/'.$event->id.'/cancel')->assertRedirect();
        $this->get($base.$path)->assertOk()->assertSee('3 events found')->assertSee('Withdrawn');
        config(['network.event_series_identity' => false]);
        $this->get($base.$path)->assertNotFound();
        $this->get($base.'/account/events')->assertOk()->assertSee('Series occurrence 1 of 3');
    }
}
