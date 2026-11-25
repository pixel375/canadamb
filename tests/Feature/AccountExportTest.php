<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_exports_require_password_and_only_include_owned_allowlisted_data(): void
    {
        $url = 'https://canadamb.ca/account/data-export';
        $member = User::factory()->create(['password' => 'Export-test-password-42']);
        $other = User::factory()->create();
        $mine = CommunityThread::create(['author_id' => $member->id, 'title' => 'My private discussion', 'body' => 'My own pending content.', 'category' => 'general']);
        CommunityThread::create(['author_id' => $other->id, 'title' => 'Other private discussion', 'body' => 'Other member content.', 'category' => 'general']);
        $this->get($url)->assertRedirect();
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Download my data');
        $this->post($url, ['current_password' => 'wrong', 'section' => 'profile'])->assertSessionHasErrors('current_password');
        $this->post($url, ['current_password' => 'Export-test-password-42', 'section' => 'users'])->assertSessionHasErrors('section');
        $data = ['current_password' => 'Export-test-password-42'];
        $this->post($url, $data + ['section' => 'profile'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('records.0.email', $member->email)->assertJsonMissingPath('records.0.password')->assertJsonMissingPath('records.0.remember_token')->assertJsonMissingPath('records.0.session_version');
        $this->post($url, $data + ['section' => 'discussions', 'user_id' => $other->id])->assertOk()
            ->assertJsonCount(1, 'records')->assertJsonPath('records.0.id', $mine->id)->assertJsonPath('next_after_id', null);
        $this->post($url, $data + ['section' => 'discussions', 'after_id' => $mine->id])->assertOk()->assertJsonCount(0, 'records');
        DB::table('user_blocks')->insert(['user_id' => $member->id, 'blocked_id' => $other->id]);
        DB::table('user_blocks')->insert(['user_id' => $other->id, 'blocked_id' => $member->id]);
        $this->post($url, $data + ['section' => 'blocked-members'])->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.blocked_id', $other->id)->assertJsonMissingPath('records.0.email');
        $this->post($url, $data + ['section' => 'blocked-members', 'after_id' => $other->id])->assertOk()->assertJsonCount(0, 'records');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.data-exported', 'actor_id' => $member->id]);
    }
}
