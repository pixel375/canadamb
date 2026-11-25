<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsCandidateReviewTest extends TestCase
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

    public function test_dismissal_can_be_restored_but_not_bypassed_through_candidate_intake(): void
    {
        $source = NewsSource::create(['name' => 'Editorial source', 'url' => 'https://example.test/', 'reuse' => 'summary-permitted', 'enabled' => true, 'feed_url' => 'https://example.test/rss']);
        $url = 'https://example.test/article';
        $id = DB::table('news_feed_candidates')->insertGetId([
            'source_id' => $source->id, 'source_hash' => hash('sha256', $url), 'source_url' => $url, 'title' => 'Candidate fixture', 'source_date' => today()->toDateString(),
        ]);
        $editor = User::factory()->create(['role' => 'news_editor']);
        $base = 'https://canadamb.ca/admin/news';
        $review = $base.'/candidate/'.$id.'/review';
        $decision = ['decision' => 'dismiss', 'reason' => 'Not relevant to Manitoba.'];
        $this->actingAs($editor)->post($review, $decision)->assertNotFound();
        config(['network.news_candidate_review' => true]);
        $this->post($review, $decision)->assertRedirect();
        $this->get($base)->assertDontSee('Candidate fixture');
        $this->get($base.'?feed_view=dismissed')->assertOk()->assertSee('Candidate fixture');
        $this->get($base.'?candidate='.$id)->assertNotFound();
        $this->post($base.'/draft', ['candidate_id' => $id])->assertNotFound();
        $this->post($review, array_replace($decision, ['decision' => 'restore']))->assertRedirect();
        $this->get($base.'?candidate='.$id)->assertOk()->assertSee('Candidate fixture');
        $this->assertDatabaseCount('news_articles', 0);
        $this->assertDatabaseCount('news_feed_candidates', 1);
    }
}
