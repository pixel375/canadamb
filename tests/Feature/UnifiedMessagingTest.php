<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\NetworkSettings;
use App\Support\NotificationFeed;
use App\Support\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_marketplace_history_and_read_markers_are_authorized_and_portal_scoped(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->listing($seller, 'elkhorn');
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $old = $thread->messages()->create(['sender_id' => $seller->id, 'body' => '<script>legacy private message</script>']);
        $inbox = '/account/messages';
        $detail = "$inbox/$thread->id";
        $this->actingAs($buyer)->get(self::CITY.$inbox)->assertOk()->assertDontSee($listing->title);
        $this->get(self::CITY.$detail)->assertNotFound();
        $this->post(self::CITY.$detail, ['body' => 'Cannot reply from this city'])->assertNotFound();
        $this->assertSame(0, $thread->fresh()->buyer_last_read_id);
        $this->get(self::MAIN.$inbox.'?q=Light%20fixture&type=marketplace')->assertOk()->assertSee($listing->title)->assertSee('1 unread');
        $this->assertSame(0, $thread->fresh()->buyer_last_read_id);
        $this->get(self::MAIN.$detail)->assertOk()->assertSee($old->body)->assertDontSee($old->body, false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame(0, $thread->fresh()->buyer_last_read_id);
        $this->assertDatabaseHas('message_reads', ['message_id' => $old->id, 'user_id' => $buyer->id]);
        $this->actingAs(User::factory()->create())->get(self::MAIN.$detail)->assertNotFound();
        $this->actingAs($buyer)->post(self::MAIN.$detail, ['body' => 'Existing chat about a sold item.'])->assertRedirect();
        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_business_and_job_starts_are_distinct_idempotent_and_role_scoped(): void
    {
        $customer = User::factory()->create();
        $representative = User::factory()->create();
        $owner = User::factory()->create();
        [$business, $branch, $job] = $this->employer($owner, $representative);
        $businessPath = "/businesses/$business->slug/branches/$branch->id/message";
        $jobPath = "/jobs/$job->id/message";
        $key = (string) Str::uuid();
        $message = ['body' => 'Request from a customer.', 'idempotency_key' => $key];
        $this->actingAs($customer)->post(self::CITY.$businessPath, $message)->assertRedirect();
        $this->post(self::CITY.$businessPath, $message)->assertRedirect();
        $duplicate = ['body' => $message['body'], 'idempotency_key' => (string) Str::uuid()];
        $this->post(self::CITY.$businessPath, $duplicate)->assertRedirect();
        $this->travel(31)->seconds();
        $this->post(self::CITY.$businessPath, $duplicate)->assertRedirect();
        $this->post(self::CITY.$jobPath, $message)->assertConflict();
        $this->post(self::CITY.$jobPath, ['body' => 'Question about this posting.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('notifications', 2);
        $contact = Conversation::where('context_type', 'business')->sole();
        $hiring = Conversation::where('context_type', 'job')->sole();
        $this->assertSame($representative->id, $contact->seller_id);
        $this->assertNull($contact->listing_id);
        $this->assertSame($job->id, $hiring->job_posting_id);

        $this->actingAs($owner)->get(self::CITY."/account/messages/$contact->id")->assertNotFound();
        $this->actingAs($representative)->get(self::MAIN.'/account/messages?type=job&q=Sales')->assertOk()
            ->assertSee('Sales assistant')->assertDontSee("/account/messages/$contact->id");
        $this->get(self::CITY.'/account/notifications?category=messages')->assertOk()
            ->assertSee('New business message')->assertSee('New job message')->assertDontSee($message['body']);
        $this->get(self::CITY."/account/messages/$contact->id")->assertOk();
        $this->get(self::MAIN.'/account/notifications?category=messages&status=unread')->assertOk()
            ->assertDontSee('New business message')->assertSee('New job message');

        $job->forceFill(['status' => 'expired'])->save();
        $this->post(self::CITY."/account/messages/$hiring->id", ['body' => 'An existing hiring follow-up.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $business->members()->detach($representative->id);
        $this->get(self::CITY."/account/messages/$hiring->id")->assertNotFound();
        $this->get(self::CITY."/account/messages/$contact->id")->assertNotFound();
        $this->get(self::CITY.'/account/notifications?status=unread')->assertOk()->assertDontSee('New job message');
        $this->actingAs($customer)->get(self::MAIN."/account/messages/$contact->id")->assertOk()->assertSee($message['body']);
        $this->post(self::CITY."/account/messages/$contact->id", ['body' => 'The recipient no longer represents the business.', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('messages', 3);

        $branch->forceFill(['message_contact_user_id' => $owner->id])->save();
        $this->post(self::CITY.$businessPath, ['body' => 'New inquiry for the current responder.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $replacement = Conversation::where('seller_id', $owner->id)->sole();
        $this->actingAs($owner)->get(self::CITY."/account/messages/$replacement->id")->assertOk()
            ->assertSee('New inquiry for the current responder.')->assertDontSee($message['body']);
        $this->get(self::CITY."/account/messages/$contact->id")->assertNotFound();
    }

    public function test_wrong_city_and_unclaimed_or_disabled_contacts_do_not_create_threads(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->create();
        [$business, $branch, $job] = $this->employer($owner, $owner, 'elkhorn');
        $path = "/businesses/$business->slug/branches/$branch->id/message";
        $payload = ['body' => 'Can you help?', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($customer)->post(self::CITY.$path, $payload)->assertNotFound();
        $this->post(self::CITY."/jobs/$job->id/message", $payload)->assertNotFound();
        $job->forceFill(['message_contact_user_id' => null])->save();
        $this->post(self::MAIN."/jobs/$job->id/message", $payload)->assertNotFound();
        $business->members()->detach($owner->id);
        $this->post(self::MAIN.$path, $payload)->assertNotFound();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_historical_message_notices_follow_conversation_access_with_all_source_modules_disabled(): void
    {
        $buyer = User::factory()->create();
        $responder = User::factory()->create();
        [$business, $branch, $job] = $this->employer(User::factory()->create(), $responder);
        $listing = $this->listing($responder, 'brandon');
        $rural = $this->listing($responder, 'elkhorn');
        $contexts = [
            ['marketplace', ['listing_id' => $listing->id], $listing->location_id],
            ['business', ['business_location_id' => $branch->id, 'location_id' => $branch->location_id], $branch->location_id],
            ['job', ['job_posting_id' => $job->id, 'location_id' => $branch->location_id], $branch->location_id],
            ['marketplace', ['listing_id' => $rural->id], $rural->location_id],
        ];
        $outsider = User::factory()->create();
        $threads = [];
        foreach ($contexts as [$type, $context, $location]) {
            $thread = Conversation::create($context + ['context_type' => $type, 'buyer_id' => $buyer->id, 'seller_id' => $responder->id]);
            $message = $thread->messages()->create(['sender_id' => $buyer->id, 'body' => 'Private conversation content.']);
            NotificationInbox::record($responder->id, 'message.received', $message->id, $message->id, [$location], $type);
            NotificationInbox::record($outsider->id, 'message.received', $message->id, $message->id, [$location], $type);
            $threads[] = $thread;
        }

        $settings = app(NetworkSettings::class);
        $settings->saveDraft($responder->id, function (array $settings): array {
            $settings['modules'] = array_fill_keys(array_keys($settings['modules']), false);

            return $settings;
        });
        $settings->publish($responder->id);

        $provincial = Site::whereNull('location_id')->sole();
        $city = Site::where('location_id', $branch->location_id)->sole();
        $feed = app(NotificationFeed::class);
        $this->assertSame(4, $feed->unread($provincial, $responder->id));
        $this->assertSame(3, $feed->unread($city, $responder->id));
        $this->assertSame(0, $feed->unread($provincial, $outsider->id));
        $this->assertSame(0, $feed->rows($city, $outsider->id, 'messages')->count());

        $this->actingAs($responder)->get(self::CITY.'/account/notifications?category=messages&status=unread')->assertOk()
            ->assertSee('New Marketplace message')->assertSee('New business message')->assertSee('New job message')
            ->assertDontSee('Private conversation content.')->assertDontSee($listing->title)->assertDontSee($job->title);
        foreach (array_slice($threads, 0, 3) as $i => $thread) {
            $this->get(self::CITY."/account/messages/$thread->id")->assertOk();
            $this->assertSame(2 - $i, $feed->unread($city, $responder->id));
        }
        $this->get(self::CITY.'/account/messages/'.$threads[3]->id)->assertNotFound();
        $this->assertSame(1, $feed->unread($provincial, $responder->id));

        $business->members()->detach($responder->id);
        $this->assertSame(1, $feed->rows($city, $responder->id, 'messages')->count());
        $this->post(self::MAIN.'/account/messages/'.$threads[3]->id.'/block')->assertRedirect();
        $this->assertSame(0, $feed->unread($provincial, $responder->id));
        $this->assertSame(0, $feed->rows($provincial, $responder->id, 'messages')->count());
    }

    public function test_blocks_apply_across_contexts_and_prevent_attachment_access(): void
    {
        Storage::fake('unified_messages');
        config(['network.media_disk' => 'unified_messages']);
        $buyer = User::factory()->create();
        $owner = User::factory()->create();
        [$business, $branch, $job] = $this->employer($owner, $owner);
        $path = "/businesses/$business->slug/branches/$branch->id/message";
        $input = ['idempotency_key' => (string) Str::uuid(), 'attachments' => [UploadedFile::fake()->image('desk.png', 500, 300)]];
        $this->actingAs($buyer)->post(self::CITY.$path, $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(self::CITY.$path, $input)->assertSessionHasNoErrors()->assertRedirect();
        $thread = Conversation::sole();
        $attachment = DB::table('message_attachments')->sole();
        $this->assertCount(1, Storage::disk('unified_messages')->allFiles());
        $url = "/account/messages/$thread->id/attachments/$attachment->id";
        $this->actingAs($owner)->get(self::CITY.$url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create())->get(self::CITY.$url)->assertNotFound();
        $this->actingAs($owner)->post(self::CITY."/account/messages/$thread->id/block")->assertRedirect();
        $this->get(self::CITY.$url)->assertNotFound();
        $this->actingAs($buyer)->get(self::CITY.$url)->assertNotFound();
        $this->post(self::CITY."/jobs/$job->id/message", ['body' => 'An alternate route.', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_attachments', 1);
    }

    public function test_reports_are_subject_to_ownership_and_do_not_echo_text_in_audit(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->listing($seller, 'brandon');
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $message = $thread->messages()->create(['sender_id' => $seller->id, 'body' => 'Private seller message.']);
        $path = self::CITY."/account/messages/$thread->id/report";
        $input = ['reason' => 'Private member report.', 'message_id' => $message->id];
        $this->actingAs(User::factory()->create())->post($path, $input)->assertNotFound();
        $this->actingAs($buyer)->post($path, $input)->assertRedirect();
        $this->post($path, $input)->assertRedirect();
        $this->assertDatabaseCount('conversation_reports', 1);
        $this->assertDatabaseHas('conversation_reports', ['reporter_id' => $buyer->id, 'message_id' => $message->id]);
        $this->assertStringNotContainsString($input['reason'], (string) DB::table('audit_logs')->latest('id')->value('context'));
    }

    public function test_verified_cookie_and_ip_policy_apply_to_private_routes_and_mutations(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->listing($seller, 'brandon');
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $path = self::CITY."/account/messages/$thread->id";
        $this->get($path)->assertRedirect('/auth/start');
        $buyer->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($buyer)->get($path)->assertRedirect(self::MAIN.'/email/verify');
        $buyer->markEmailAsVerified();

        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->withHeader('X-Forwarded-For', '192.0.2.10')
            ->get($path)->assertForbidden();
        $this->get('https://unexpected.example/account/messages')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->get($path)->assertOk();
        $this->get('https://unexpected.example/account/messages')->assertNotFound();
        $this->app->instance('env', 'production');
        $this->post($path, ['body' => 'A forged submission.', '_token' => 'forged'])->assertStatus(419);
        $this->post($path.'/report', ['reason' => 'A forged report.'])->assertStatus(419);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversation_reports', 0);
    }

    public function test_preference_and_older_pages_keep_accurate_unread_counts(): void
    {
        $buyer = User::factory()->create();
        $owner = User::factory()->create();
        [$business, $branch, $job] = $this->employer($owner, $owner);
        DB::table('member_settings')->insert(['user_id' => $owner->id, 'notify_business_messages' => false]);
        $this->actingAs($buyer)->post(self::CITY."/businesses/$business->slug/branches/$branch->id/message", [
            'body' => 'Business message without a notice.', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->post(self::CITY."/jobs/$job->id/message", ['body' => 'Job message with a notice.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('notifications', 1);
        $thread = Conversation::where('job_posting_id', $job->id)->sole();
        $thread->messages()->createMany(array_fill(0, 32, ['sender_id' => $buyer->id, 'body' => 'Legacy page content']));

        $path = self::CITY."/account/messages/$thread->id";
        $this->actingAs($owner)->get(self::CITY.'/account/messages?type=job')->assertOk()->assertSee('34 unread across this portal')->assertSee('33 unread');
        $latest = $this->get($path)->assertOk()->viewData('messages');
        $this->assertSame(0, $thread->fresh()->seller_last_read_id);
        $this->assertDatabaseCount('message_reads', 30);
        $this->get(self::CITY.'/account/messages')->assertOk()->assertSee('4 unread across this portal');
        $new = $thread->messages()->create(['sender_id' => $buyer->id, 'body' => 'Arrived after opening the latest page.']);
        $this->post($path, ['body' => 'A reply from the opened composer.', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseMissing('message_reads', ['message_id' => $new->id, 'user_id' => $owner->id]);
        $olderPath = parse_url($latest->nextPageUrl(), PHP_URL_PATH).'?'.parse_url($latest->nextPageUrl(), PHP_URL_QUERY);
        $this->get(self::CITY.$olderPath)->assertOk()->assertDontSee($new->body);
        $this->assertDatabaseCount('message_reads', 33);
        $this->get(self::CITY.'/account/messages')->assertOk()->assertSee('2 unread across this portal');
    }

    private function listing(User $seller, string $location): Listing
    {
        return Listing::forceCreate([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'title' => 'Light fixture for sale', 'description' => 'Previously posted in Manitoba.',
            'category' => 'home', 'condition' => 'good', 'price_cents' => 1200, 'status' => 'sold',
            'published_at' => now()->subWeek(), 'expires_at' => now()->addWeek(),
        ]);
    }

    /** @return array{Business, BusinessLocation, JobPosting} */
    private function employer(User $owner, User $representative, string $location = 'brandon'): array
    {
        $business = Business::create(['slug' => 'qa-neighbourhood-store', 'name' => 'Neighbourhood Store', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        if (! $owner->is($representative)) {
            $business->members()->attach($representative->id, ['role' => 'manager']);
        }
        $branch = $business->locations()->create(['location_id' => Location::where('slug', $location)->sole()->id, 'address' => '1 Test Avenue']);
        $branch->forceFill(['message_contact_user_id' => $representative->id])->save();
        $job = JobPosting::forceCreate([
            'business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Sales assistant',
            'description' => 'Work with the shop team.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/jobs', 'closing_on' => now()->addWeek()->toDateString(),
            'status' => 'approved', 'published_at' => now(), 'message_contact_user_id' => $representative->id,
        ]);

        return [$business, $branch, $job];
    }
}
