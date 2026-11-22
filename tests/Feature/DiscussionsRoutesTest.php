<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionsRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_provincial_reader_keeps_host_and_city_has_the_only_canonical_thread_url(): void
    {
        $author = User::factory()->create();
        $local = $this->thread($author, 'Brandon trail', 'brandon');
        $rural = $this->thread($author, 'Elkhorn trail', 'elkhorn');
        $provincial = $this->thread($author, 'Provincial trail', null);
        $path = "/discussions/$local->id";
        $canonical = '<link rel="canonical" href="'.self::CITY.$path.'">';
        $this->get(self::MAIN.$path)->assertOk()->assertSee($canonical, false);
        $this->get(self::CITY.$path)->assertOk()->assertSee($canonical, false);
        $this->get(self::CITY."/discussions/$rural->id")->assertNotFound();
        $this->get(self::CITY."/discussions/$provincial->id")->assertNotFound();
        $this->get(self::MAIN.'/sitemap.xml')->assertOk()->assertDontSee($path)->assertSee("/discussions/$provincial->id")->assertDontSee('/community/');
        $this->get(self::CITY.'/sitemap.xml')->assertOk()->assertSee(self::CITY.$path)->assertDontSee("/discussions/$rural->id");
        $this->get(self::MAIN."/community/$local->id?page=2&next=https://example.test")->assertStatus(301)
            ->assertRedirect(self::MAIN.$path.'?page=2');
        $this->get(self::CITY."/community/$rural->id")->assertNotFound();
        $this->get(self::CITY.$path.'/participate')->assertRedirect('/auth/start');
        $this->assertSame(self::CITY.$path.'/participate', session('url.intended'));
        $this->get(self::MAIN.'/community?category=general&q=trail&page=2&host=example.test')->assertStatus(301)
            ->assertRedirect(self::MAIN.'/discussions?q=trail&category=general&page=2');
        $this->get(self::MAIN.'/community?category=bogus&page=-1&q%5B0%5D=wrong')->assertStatus(301)
            ->assertRedirect(self::MAIN.'/discussions');
    }

    public function test_filters_escape_wildcards_and_respect_category_location_sort_and_pagination(): void
    {
        $author = User::factory()->create();
        $rural = $this->thread($author, '100% trail', 'elkhorn');
        $this->thread($author, '100 green trail', 'elkhorn');
        $this->thread($author, '100% Brandon', 'brandon');
        $wrongCategory = $this->thread($author, '100% other category', 'elkhorn');
        $wrongCategory->forceFill(['category' => 'help'])->save();
        $this->get(self::MAIN.'/discussions?q=100%25&category=general&location='.$rural->location_id)->assertOk()
            ->assertSee($rural->title)->assertDontSee('100 green trail')->assertDontSee('100% Brandon')->assertDontSee($wrongCategory->title);
        $this->getJson(self::CITY.'/discussions?location='.$rural->location_id)->assertUnprocessable();
        $pinned = $this->thread($author, 'Pinned local topic', 'brandon');
        $pinned->forceFill(['pinned' => true, 'created_at' => now()->subWeek(), 'last_activity_at' => now()->subWeek()])->save();
        foreach (range(1, 19) as $i) {
            $this->thread($author, "Topic number $i", 'brandon');
        }
        $this->get(self::CITY.'/discussions?category=general&sort=newest')->assertOk()
            ->assertSeeInOrder(['Pinned local topic', 'Topic number 19'])->assertSee('sort=newest')->assertDontSee($rural->title);
        $this->get(self::CITY.'/discussions?category=general&sort=newest&page=2')->assertOk()
            ->assertSee('Topic number 1')->assertDontSee('Pinned local topic');
    }

    public function test_new_and_legacy_mutations_use_verification_visibility_and_the_same_approval_workflow(): void
    {
        $writer = User::factory()->create();
        $follower = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $post = ['title' => 'Brandon garden club', 'body' => 'A discussion for local neighbours.', 'category' => 'general'];
        $this->actingAs(User::factory()->unverified()->create())->post(self::CITY.'/discussions', $post)
            ->assertRedirect(self::MAIN.'/email/verify');
        $this->actingAs($writer)->get(self::CITY.'/community/new')->assertStatus(301)->assertRedirect(self::CITY.'/discussions/new');
        $this->post(self::CITY.'/community', $post)->assertRedirect();
        $thread = CommunityThread::sole();
        $old = "/community/$thread->id";
        $current = "/discussions/$thread->id";
        $this->get(self::CITY.$old)->assertNotFound();
        $this->actingAs($admin)->post(self::MAIN."/admin/community/threads/$thread->id", [
            'action' => 'approve', 'version' => ModerationDecision::version('thread', $thread->fresh()),
        ])->assertRedirect();
        $this->actingAs($follower)->post(self::MAIN.$old.'/follow')->assertRedirect(self::MAIN.$current);
        $this->post(self::CITY.$current.'/follow')->assertRedirect();
        $this->assertDatabaseCount('community_thread_follows', 1);
        $response = $this->actingAs($writer)->post(self::MAIN.$old.'/replies', ['body' => 'First reply awaits review.']);
        $response->assertRedirect(self::MAIN.$current)->assertSessionHas('status', 'Reply submitted for review.');
        $reply = $thread->replies()->sole();
        $this->get(self::MAIN.$current)->assertDontSee($reply->body);
        $this->assertDatabaseMissing('notifications', ['event' => 'reply.followed', 'user_id' => $follower->id]);
        $review = ['action' => 'approve', 'version' => ModerationDecision::version('reply', $reply)];
        $this->actingAs($admin)->post(self::MAIN."/admin/community/replies/$reply->id", $review)->assertRedirect();
        $this->post(self::MAIN."/admin/community/replies/$reply->id", $review)->assertConflict();
        $this->assertSame(1, DB::table('notifications')->where(['event' => 'reply.followed', 'user_id' => $follower->id])->count());
        $this->actingAs($follower)->delete(self::CITY.$old.'/follow')->assertRedirect(self::CITY.$current);
        $this->assertDatabaseCount('community_thread_follows', 0);
        $rural = $this->thread($writer, 'Rural topic', 'elkhorn');
        $this->post(self::CITY."/discussions/$rural->id/follow")->assertNotFound();
        $this->delete(self::CITY."/discussions/$rural->id/follow")->assertNotFound();
        $this->post(self::CITY."/discussions/$rural->id/replies", ['body' => 'A wrong-city reply.'])->assertNotFound();
        $this->post(self::CITY."/reports/thread/$rural->id", ['reason' => 'A wrong-city report.'])->assertNotFound();
        $this->post(self::CITY."/reports/thread/$thread->id", ['reason' => 'Please review this public discussion.'])->assertRedirect(self::CITY.$current);
    }

    public function test_disabled_module_prelaunch_and_csrf_protect_current_and_legacy_entry_points(): void
    {
        $member = User::factory()->create();
        $thread = $this->thread($member, 'Private QA thread', 'brandon');
        $path = '/discussions/'.$thread->id;
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.1']]);
        $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->withHeader('X-Forwarded-For', '192.0.2.1')->get(self::CITY.$path)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->get(self::CITY.$path)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->app->instance('env', 'production');
        $this->post(self::CITY.$path.'/replies', ['body' => 'Invalid CSRF proof.'])->assertStatus(419);
        $this->post(self::CITY."/community/$thread->id/replies", ['body' => 'Invalid legacy CSRF proof.'])->assertStatus(419);
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($member->id, function (array $draft): array {
            $draft['modules']['community'] = false;

            return $draft;
        });
        $settings->publish($member->id);
        $this->get(self::CITY.$path)->assertNotFound();
        $this->get(self::CITY."/community/$thread->id")->assertNotFound();
        $this->assertDatabaseCount('community_replies', 0);
    }

    public function test_title_search_shares_a_limit_between_legacy_and_canonical_paths(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(self::MAIN.'/community?q=trail')->assertStatus(301);
        }
        $this->get(self::MAIN.'/discussions?q=trail')->assertTooManyRequests();
        $this->get(self::MAIN.'/discussions')->assertOk();
    }

    private function thread(User $author, string $title, ?string $location): CommunityThread
    {
        return CommunityThread::forceCreate([
            'author_id' => $author->id, 'title' => $title, 'body' => 'This is a public discussion.',
            'location_id' => $location === null ? null : Location::where('slug', $location)->sole()->id,
            'category' => 'general', 'status' => 'approved', 'last_activity_at' => now(),
        ]);
    }
}
