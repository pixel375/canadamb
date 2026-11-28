<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use App\Models\User;
use App\Support\NewsFeeds\FeedFailure;
use App\Support\NewsFeeds\FeedTransport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsFeedTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_scheduled_polling_stores_deduplicated_candidates_for_editorial_review(): void
    {
        $source = $this->source('City updates', 'https://news.example.test');
        $transport = $this->mock(FeedTransport::class);
        $transport->shouldReceive('fetch')->twice()->with($source->feed_url, \Mockery::type('int'))->andReturn(
            file_get_contents(base_path('tests/Fixtures/news-rss.xml')),
        );

        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseCount('news_feed_checks', 0);
        config(['network.news_polling' => true]);

        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseHas('news_feed_checks', ['source_id' => $source->id, 'status' => 'success', 'new_candidates' => 1]);
        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseCount('news_feed_checks', 1);
        $this->travel(61)->minutes();
        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseCount('news_feed_candidates', 1);
        $this->assertDatabaseHas('news_feed_candidates', ['title' => 'City publishes meeting schedule']);
        $this->assertDatabaseCount('news_articles', 0);

        $id = DB::table('news_feed_candidates')->value('id');
        $this->actingAs(User::factory()->create(['role' => 'news_editor']))
            ->get("https://canadamb.ca/admin/news?candidate=$id")->assertOk()->assertSee('City publishes meeting schedule')
            ->assertSee('https://news.example.test/meeting')->assertDontSee('Full publisher description');
        $this->get('https://canadamb.ca/admin/news?feed_q=city%20schedule&feed_source='.$source->id)->assertOk()->assertSee('City publishes meeting schedule');
        $this->get('https://canadamb.ca/admin/news?feed_q=%25')->assertOk()->assertDontSee('City publishes meeting schedule')->assertSee('Filter candidates');
        $draft = [
            'candidate_id' => $id, 'category' => 'municipal', 'title' => 'Council will meet next week',
            'source_date' => '2026-04-20', 'body' => 'Council has posted the next public meeting notice and schedule.',
        ];
        $this->post('https://canadamb.ca/admin/news/draft', $draft)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('https://canadamb.ca/admin/news/draft', $draft)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('news_articles', ['status' => 'draft', 'source_url' => 'https://news.example.test/meeting']);
        $this->assertDatabaseCount('news_articles', 1);
        $this->get('https://canadamb.ca/news')->assertDontSee('Council will meet next week');
        $this->get('https://canadamb.ca/admin/news')->assertDontSee('City publishes meeting schedule');
    }

    public function test_feed_failures_are_recorded_and_retry_after_backoff(): void
    {
        config(['network.news_polling' => true]);
        $source = $this->source('Retry source', 'https://retry.example.test');
        $transport = $this->mock(FeedTransport::class);
        $transport->shouldReceive('fetch')->once()->with($source->feed_url, \Mockery::type('int'))->andThrow(new FeedFailure('Connection failed'));
        $transport->shouldReceive('fetch')->once()->with($source->feed_url, \Mockery::type('int'))->andReturn(
            file_get_contents(base_path('tests/Fixtures/news-atom.xml')),
        );

        $this->artisan('news:poll')->assertFailed();
        $this->assertDatabaseHas('news_feed_checks', ['source_id' => $source->id, 'status' => 'failed']);
        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseCount('news_feed_checks', 1);
        $this->travel(16)->minutes();
        $this->artisan('news:poll')->assertSuccessful();
        $this->assertDatabaseHas('news_feed_checks', ['source_id' => $source->id, 'status' => 'success', 'new_candidates' => 1]);
        $this->assertDatabaseHas('news_feed_candidates', ['title' => 'Local park reopening']);
        $this->assertDatabaseHas('news_sources', ['id' => $source->id, 'poll_failures' => 0]);
        $url = 'https://canadamb.ca/admin/news/feed-health';
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'news_editor']))->get($url.'?source_id='.$source->id.'&period=7&status=failed')
            ->assertOk()->assertSee('1 successful checks')->assertSee('1 failed checks')->assertSee('Retry source')->assertDontSee('Connection failed');
        $this->get($url.'?status=success')->assertOk()->assertSee('New candidates');
        $this->assertDatabaseCount('news_feed_checks', 2);
    }

    public function test_network_admin_can_configure_only_same_host_feeds(): void
    {
        $source = $this->source('Configurable source', 'https://news.example.test');
        $uri = "https://canadamb.ca/admin/news/source/$source->id/feed";
        $this->actingAs(User::factory()->create(['role' => 'news_editor']))
            ->post($uri, ['feed_url' => 'https://news.example.test/feed'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post($uri, ['feed_url' => 'https://news.example.test/feed'])->assertSessionHasErrors('version');
        $version = $source->fresh()->configurationVersion();
        $this->post($uri, ['feed_url' => 'https://different.example.test/feed', 'version' => $version])->assertSessionHasErrors('feed_url');
        $this->post($uri, ['feed_url' => 'https://news.example.test/new-feed', 'version' => $version])->assertSessionHasNoErrors();
        $this->post($uri, ['feed_url' => 'https://news.example.test/stale-feed', 'version' => $version])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('news_sources', ['id' => $source->id, 'feed_url' => 'https://news.example.test/new-feed']);
        $status = "https://canadamb.ca/admin/news/source/$source->id";
        $rights = $status.'/rights';
        $this->post($status, ['enabled' => '0'])->assertSessionHasErrors('version');
        $this->post($status, ['enabled' => '0', 'version' => $version])->assertSessionHasErrors('version');
        $policy = ['reuse' => 'link-only', 'reuse_notes' => 'Summary rights are not currently confirmed.'];
        $this->post($rights, $policy)->assertSessionHasErrors('version');
        $this->post($rights, $policy + ['version' => $version])->assertSessionHasErrors('version');
        $this->post($rights, $policy + ['version' => $source->fresh()->configurationVersion()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('news_sources', ['id' => $source->id, 'reuse' => 'link-only', 'enabled' => true]);
        $this->post($status, ['enabled' => '0', 'version' => $source->fresh()->configurationVersion()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('news_sources', ['id' => $source->id, 'enabled' => false]);
    }

    public function test_an_empty_feed_is_logged_without_stopping_the_rest_of_the_batch(): void
    {
        config(['network.news_polling' => true]);
        $empty = $this->source('Empty feed', 'https://blank.example.test');
        $working = $this->source('Working feed', 'https://news.example.test');
        $transport = $this->mock(FeedTransport::class);
        $transport->shouldReceive('fetch')->once()->with($empty->feed_url, \Mockery::type('int'))->andReturn('');
        $transport->shouldReceive('fetch')->once()->with($working->feed_url, \Mockery::type('int'))->andReturn(
            file_get_contents(base_path('tests/Fixtures/news-rss.xml')),
        );

        $this->artisan('news:poll')->assertFailed();
        $this->assertDatabaseHas('news_feed_checks', ['source_id' => $empty->id, 'status' => 'failed']);
        $this->assertDatabaseHas('news_feed_checks', ['source_id' => $working->id, 'status' => 'success', 'new_candidates' => 1]);
    }

    private function source(string $name, string $url): NewsSource
    {
        return NewsSource::create([
            'name' => $name, 'url' => $url.'/', 'enabled' => true, 'reuse' => 'summary-permitted', 'feed_url' => $url.'/feed',
        ]);
    }
}
