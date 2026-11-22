<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Identity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageContactTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    protected $seeder = DatabaseSeeder::class;

    public function test_branch_contact_requires_self_consent_and_can_be_replaced_after_role_revocation(): void
    {
        $business = $this->business();
        $branch = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Main Street']);
        $owner = $this->member($business, 'owner');
        $manager = $this->member($business, 'manager');
        $editor = $this->member($business, 'editor');
        $settings = self::CITY."/account/businesses/$business->id/branches/$branch->id/message-contact";
        $detail = self::CITY."/businesses/$business->slug";
        $contact = "/businesses/$business->slug/branches/$branch->id/contact";

        $this->get($detail)->assertOk()->assertDontSee($contact);
        $this->actingAs($editor)->put($settings, ['enabled' => '1'])->assertNotFound();
        $this->actingAs($owner)->put($settings, ['enabled' => '1', 'message_contact_user_id' => $manager->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('business_locations', ['id' => $branch->id, 'message_contact_user_id' => $owner->id]);
        $this->actingAs($manager)->get($detail)->assertOk()->assertSee($contact)->assertDontSee($owner->email);
        $this->put($settings, ['enabled' => '1'])->assertSessionHasErrors('enabled');
        $this->put($settings, ['enabled' => '0'])->assertSessionHasErrors('enabled');

        $business->members()->updateExistingPivot($owner->id, ['role' => 'editor']);
        $this->get($detail)->assertOk()->assertDontSee($contact);
        $this->put($settings, ['enabled' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('business_locations', ['id' => $branch->id, 'message_contact_user_id' => $manager->id]);
        $this->put($settings, ['enabled' => '0'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('business_locations', ['id' => $branch->id, 'message_contact_user_id' => null]);
    }

    public function test_multi_community_business_shows_only_local_branch_contacts_and_keeps_one_canonical(): void
    {
        $business = $this->business();
        $owner = $this->member($business, 'owner');
        $winnipeg = $business->locations()->create(['location_id' => $this->location('winnipeg'), 'address' => '2 Main Street']);
        $brandon = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Main Street']);
        $path = "/businesses/$business->slug";
        $settings = "/account/businesses/$business->id/branches";

        $this->actingAs($owner)->put(self::CITY."$settings/$winnipeg->id/message-contact", ['enabled' => '1'])->assertNotFound();
        $this->put(self::NETWORK."$settings/$winnipeg->id/message-contact", ['enabled' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->put(self::CITY."$settings/$brandon->id/message-contact", ['enabled' => '1'])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs(User::factory()->create())->get(self::CITY.$path)->assertOk()
            ->assertSee('<link rel="canonical" href="'.self::NETWORK.$path.'">', false)
            ->assertSee("$path/branches/$brandon->id/contact")->assertDontSee("$path/branches/$winnipeg->id/contact");
        $this->get(self::NETWORK.$path)->assertOk()->assertSee("$path/branches/$brandon->id/contact")->assertSee("$path/branches/$winnipeg->id/contact");
    }

    public function test_job_contact_accepts_a_verified_manager_independently_of_the_job_author(): void
    {
        $business = $this->business();
        $owner = $this->member($business, 'owner');
        $manager = $this->member($business, 'manager');
        $unverified = User::factory()->unverified()->create();
        $business->members()->attach($unverified->id, ['role' => 'manager']);
        $branch = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Main Street']);
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Store supervisor',
            'description' => 'Supervise the store and support the team.', 'employment_type' => 'full-time',
            'workplace' => 'on-site', 'apply_url' => 'https://example.test/careers', 'closing_on' => now()->addWeek()->toDateString(),
        ]);
        $settings = self::CITY."/account/businesses/$business->id/jobs/$job->id/message-contact";
        $detail = self::CITY."/jobs/$job->id";

        $this->actingAs($unverified)->put($settings, ['enabled' => '1'])->assertRedirect(self::NETWORK.'/email/verify');
        $this->actingAs($manager)->put($settings, ['enabled' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'author_id' => $owner->id, 'status' => 'pending', 'message_contact_user_id' => $manager->id]);
        $this->get($detail)->assertNotFound();

        $job->forceFill(['status' => 'approved', 'published_at' => now()])->save();
        $this->actingAs($owner)->get($detail)->assertOk()->assertSee("/jobs/$job->id/contact")->assertDontSee($manager->email);
        $this->actingAs($manager)->get($detail)->assertOk()->assertDontSee("/jobs/$job->id/contact");

        $job->forceFill(['status' => 'withdrawn'])->save();
        $this->put($settings, ['enabled' => '0'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'withdrawn', 'message_contact_user_id' => null]);
    }

    public function test_editors_cannot_publish_business_phone_or_email(): void
    {
        $business = $this->business();
        $owner = $this->member($business, 'owner');
        $editor = $this->member($business, 'editor');
        $branch = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Main Street']);
        $settings = self::CITY."/account/businesses/$business->id";

        $this->actingAs($owner)->put($settings, ['public_email' => 'office@example.test', 'phone' => '204-555-0100'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($editor)->get($settings)->assertOk()->assertDontSee('name="public_email"', false)->assertDontSee("branches/$branch->id/message-contact");
        $this->put($settings, ['description' => 'Repairs bicycles.', 'public_email' => 'private@example.test', 'phone' => '204-555-0101'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get(self::CITY."/businesses/$business->slug")->assertOk()->assertSee('Repairs bicycles.')->assertSee('office@example.test')->assertSee('204-555-0100')
            ->assertDontSee('private@example.test')->assertDontSee('204-555-0101');
    }

    public function test_business_and_job_notice_preferences_can_be_saved_independently(): void
    {
        $member = User::factory()->create();
        $settings = self::NETWORK.'/account/settings';

        $this->actingAs($member)->get($settings)->assertOk()->assertSee('name="notify_business_messages"', false)->assertSee('name="notify_job_messages"', false);
        $this->post($settings, ['name' => $member->name, 'notify_business_messages' => '0', 'notify_job_messages' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($settings, ['name' => 'Updated member', 'notify_marketplace_messages' => '0'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('member_settings', [
            'user_id' => $member->id, 'notify_business_messages' => false, 'notify_job_messages' => true,
            'notify_marketplace_messages' => false,
        ]);
    }

    public function test_brandon_contact_routes_preserve_the_exact_path_in_pkce_session_state(): void
    {
        Identity::provision(Site::with('domain')->where('code', 'brandon')->sole());
        foreach (['/businesses/test-business/branches/1/contact', '/jobs/1/contact'] as $path) {
            $this->get(self::CITY.$path)->assertRedirect(self::CITY.'/auth/start')
                ->assertSessionHas('url.intended', self::CITY.$path);

            $start = $this->get(self::CITY.'/auth/start')->assertRedirect();
            $destination = $start->headers->get('Location');
            $this->assertStringStartsWith(self::NETWORK.'/oauth/authorize?', $destination);
            parse_str(parse_url($destination, PHP_URL_QUERY), $parameters);
            $this->assertSame('S256', $parameters['code_challenge_method']);
            $start->assertSessionHas('community_oauth.'.$parameters['state'].'.path', $path);
            $this->assertStringNotContainsString($path, $destination);
        }
    }

    private function business(): Business
    {
        return Business::create(['slug' => 'test-business', 'name' => 'Test Business', 'category' => 'services', 'status' => 'active']);
    }

    private function member(Business $business, string $role): User
    {
        $member = User::factory()->create();
        $business->members()->attach($member->id, ['role' => $role]);

        return $member;
    }

    private function location(string $slug): int
    {
        return Location::where('slug', $slug)->sole()->id;
    }
}
