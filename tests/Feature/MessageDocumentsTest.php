<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageDocumentsTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            DB::table('message_documents')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_plain_text_documents_are_bounded_private_and_individually_reportable(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $listing = Listing::create(['user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Text attachment fixture', 'description' => 'Private document fixture.', 'category' => 'furniture',
            'condition' => 'good', 'price_cents' => 100, 'status' => 'active']);
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $base = 'https://brandonmb.ca';
        $url = $base.'/account/messages/'.$thread->id;
        $body = 'Private text fixture for the recipient.';
        $upload = fn (string $name, string $content) => ['body' => 'Attachment fixture', 'document' => UploadedFile::fake()->createWithContent($name, $content)];
        $this->actingAs($buyer)->post($url, $upload('notes.txt', $body))->assertSessionHasErrors('document');
        config(['network.message_documents' => true, 'network.content_reports' => true]);
        $this->post($url, $upload('notes.pdf', $body))->assertSessionHasErrors('document');
        $this->post($url, $upload('notes.txt', "<?php echo 'bad';"))->assertSessionHasErrors('document');
        $this->post($url, $upload('notes.txt', str_repeat('a', 60001)))->assertSessionHasErrors('document');
        $this->post($url, $upload('notes.txt', $body))->assertRedirect()->assertSessionHasNoErrors();
        $document = DB::table('message_documents')->sole();
        $this->assertSame(hash('sha256', $body), $document->sha256);
        $migration = require database_path('proposed-migrations/2026_11_27_000022_create_message_documents.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse retained private documents.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('retention', $exception->getMessage());
        }
        $this->assertDatabaseCount('message_documents', 1);
        $download = $url.'/documents/'.$document->message_id;
        $this->get($download)->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertHeader('Content-Disposition', 'attachment; filename="canadamb-document.txt"')->assertSee($body);
        $this->actingAs($admin)->get($download)->assertNotFound();
        $this->actingAs($seller)->get($url)->assertOk()->assertSee('Download text attachment')->assertDontSee($body);
        $this->postJson($url.'/sync', ['after' => 0])->assertJsonPath('messages.0.has_document', true)->assertDontSee($body);
        $this->get($download)->assertOk();
        $this->post($base.'/account/report/message-document/'.$document->message_id, ['reason' => 'spam', 'explanation' => 'Suspicious text attachment reported.'])->assertRedirect();
        $report = DB::table('content_reports')->sole();
        $review = 'https://canadamb.ca/admin/content-reports/'.$report->id;
        $this->actingAs($admin)->get('https://canadamb.ca/admin/content-reports')->assertOk()->assertSee('Download reported text attachment')->assertDontSee($body);
        $this->post($review.'/evidence')->assertOk()->assertSee($body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.report-document-accessed', 'actor_id' => $admin->id]);
        $this->post($review, ['note' => 'Independent document review complete.'])->assertRedirect();
        $this->post($review.'/evidence')->assertNotFound();
        $this->actingAs($seller)->post($url.'/block')->assertRedirect();
        $this->actingAs($buyer)->post($url, $upload('notes.txt', $body))->assertForbidden();
        $this->assertDatabaseCount('message_documents', 1);
    }
}
