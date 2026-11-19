<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\NewsSource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_authenticated_idempotent_submission_requires_editorial_review_before_publication(): void
    {
        config(['network.news_ingest_token' => str_repeat('x', 40)]);
        $place = Location::where('slug', 'brandon')->sole();
        $source = NewsSource::create([
            'name' => 'An official source', 'url' => 'https://source.example.test/', 'coverage_location_id' => $place->id,
            'reuse' => 'summary-permitted', 'enabled' => true,
        ]);
        $payload = [
            'source_id' => $source->id, 'external_key' => '12345',
            'source_url' => 'https://source.example.test/news/council',
            'title' => 'Brandon council scheduled a public meeting',
            'body' => 'The source posted the date and public attendance details for the next council meeting.',
            'category' => 'municipal', 'source_date' => now()->toDateString(), 'sensitive' => false,
        ];
        $uri = 'https://canadamb.ca/api/v1/news/ingest';
        $this->postJson($uri, $payload)->assertUnauthorized();
        $response = $this->withToken(str_repeat('x', 40))->postJson($uri, $payload)->assertCreated()->assertJsonPath('status', 'draft');
        $id = $response->json('id');
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($uri, array_replace($payload, ['sensitive' => true]))->assertConflict();
        $this->postJson($uri, array_replace($payload, ['source_url' => 'https://irrelevant.example.test/story']))->assertUnprocessable();
        $this->assertDatabaseCount('news_articles', 1);
        $this->get('https://brandonmb.ca/news')->assertOk()->assertDontSee($payload['title']);

        $this->actingAs(User::factory()->create(['role' => 'news_editor']))
            ->get('https://canadamb.ca/admin/news')->assertOk()->assertSee($payload['title']);
        $this->post("https://canadamb.ca/admin/news/$id/publish", ['verification' => 'Checked the stated date and details against the original public notice.'])->assertRedirect();
        $this->get('https://brandonmb.ca/news')->assertSee($payload['title']);
        $this->get('https://canadamb.ca/news')->assertSee($payload['title']);
        $this->get("https://brandonmb.ca/news/$id")->assertOk()->assertSee($payload['source_url'])->assertSee($source->name);
        $this->get("https://canadamb.ca/news/$id")->assertStatus(301)->assertRedirect("https://brandonmb.ca/news/$id");
        $this->get('https://brandonmb.ca/sitemap.xml')->assertSee("https://brandonmb.ca/news/$id");
        $this->get('https://canadamb.ca/sitemap.xml')->assertDontSee("/news/$id");
    }
}
