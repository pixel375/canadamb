<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\BusinessImports\BusinessImportPlan;
use App\Support\BusinessImports\BusinessSources;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReviewedBusinessImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private User $operator;

    private BusinessImportPlan $imports;

    private int $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['role' => 'network_admin']);
        $this->imports = app(BusinessImportPlan::class);
        $this->source = app(BusinessSources::class)->register($this->operator, 'Synthetic licensed register', 'https://example.test',
            'Synthetic test records', 'https://example.test/terms', '2026-01-01');
    }

    public function test_preview_and_review_group_branches_without_replacing_history(): void
    {
        $body = "one,Example Repair,services,brandon,1 Example Street,https://example.test,organization\n"
            ."two,Example Repair,services,winnipeg,2 Example Avenue,https://example.test,organization\n";
        $unapproved = $this->stage($body);
        $this->assertDatabaseCount('businesses', 0);
        $this->expectRejected(fn () => $this->apply($unapproved), 'current approved');
        $this->approve();
        $plan = $this->stage($body);
        $this->assertSame(['create_branch' => 1, 'create_business' => 1], $this->imports->counts($this->operator, $plan->id));
        $this->assertDatabaseCount('businesses', 0);
        $this->apply($plan);
        $business = Business::sole();
        $firstIds = $business->locations()->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $firstIds);
        $this->expectRejected(fn () => $this->apply($plan), 'already applied');
        $next = $this->stage($body);
        $this->assertSame(['unchanged' => 2], $this->imports->counts($this->operator, $next->id));
        $this->apply($next);
        $this->assertSame($business->id, Business::sole()->id);
        $this->assertSame($firstIds, $business->locations()->orderBy('id')->pluck('id')->all());
        $path = '/businesses/'.$business->slug;
        $this->get('https://canadamb.ca'.$path)->assertOk()->assertSee('1 Example Street')->assertSee('2 Example Avenue');
        $this->get('https://brandonmb.ca'.$path)->assertOk()->assertSee('1 Example Street')->assertDontSee('2 Example Avenue')
            ->assertSee("https://canadamb.ca$path");
    }

    public function test_explicit_conflict_resolution_keeps_business_and_branch_identity(): void
    {
        $this->approve();
        $existing = Business::create(['slug' => 'historic-business', 'name' => 'Existing Repair', 'status' => 'active',
            'category' => 'services', 'website' => 'https://existing.example.test']);
        $row = "new,Existing Repair,services,brandon,31 Example Avenue,https://source.example.test,new-group\n";
        $plan = $this->stage($row);
        $this->assertSame(['identity_conflict' => 1], $this->imports->counts($this->operator, $plan->id));
        $this->expectRejected(fn () => $this->apply($plan), 'unresolved');
        $this->imports->resolve($this->operator, $plan->id, $plan->review_token, 2, 'link', $existing->id, 'Verified against the synthetic registry identifier.');
        $this->expectRejected(fn () => $this->apply($plan), 'token');
        $current = DB::table('business_import_runs')->find($plan->id);
        $this->apply($current);
        $branch = $existing->locations()->sole();
        $this->assertSame('historic-business', Business::sole()->slug);
        $this->assertSame('https://existing.example.test', $existing->fresh()->website);
        $this->assertDatabaseHas('business_import_rows', ['run_id' => $plan->id, 'result_branch_id' => $branch->id, 'result_business_id' => $existing->id]);
    }

    public function test_ownership_changes_and_competing_plans_invalidate_the_review(): void
    {
        $this->approve();
        $original = "a,Synthetic Auto,services,brandon,10 Test Road,https://example.test,synthetic\n";
        $first = $this->stage($original);
        $second = $this->stage($original);
        $this->apply($first);
        $this->expectRejected(fn () => $this->apply($second), 'changed');
        $business = Business::sole();
        $edited = str_replace('10 Test Road', '11 Test Road', $original);
        $beforeOwner = $this->stage($edited);
        $this->assertSame(['update_facts' => 1], $this->imports->counts($this->operator, $beforeOwner->id));
        $business->members()->attach(User::factory()->create(), ['role' => 'owner']);
        $this->expectRejected(fn () => $this->apply($beforeOwner), 'changed');
        $afterOwner = $this->stage($edited);
        $this->assertSame(['claimed_conflict' => 1], $this->imports->counts($this->operator, $afterOwner->id));
        $this->assertSame('10 Test Road', $business->locations()->sole()->address);
    }

    public function test_source_revision_and_permission_are_checked_on_every_write(): void
    {
        $this->approve();
        $plan = $this->stage("a,Another Repair,services,brandon,10 Test Road,,another\n");
        app(BusinessSources::class)->revise($this->operator, $this->source, 1, 'https://example.test/changed',
            'Revised synthetic license', 'https://example.test/terms', '2026-01-02');
        $this->expectRejected(fn () => $this->apply($plan), 'current approved');
        $this->operator->forceFill(['role' => 'user'])->save();
        $this->expectException(AuthorizationException::class);
        $this->stage("a,Another Repair,services,brandon,10 Test Road,,another\n");
    }

    public function test_full_snapshot_flags_missing_branches_without_removing_them(): void
    {
        $this->approve();
        $first = "one,Example Store,retail,brandon,1 Test Road,,store\n";
        $second = "two,Example Store,retail,elkhorn,2 Test Road,,store\n";
        $this->apply($this->stage($first.$second));
        $this->apply($this->stage($first));
        $this->assertSame(0, DB::table('business_locations')->whereNotNull('import_stale_at')->count());
        $this->apply($this->stage($first, true));
        $this->assertDatabaseCount('business_locations', 2);
        $this->assertNotNull(DB::table('business_locations')->where('external_key', 'two')->value('import_stale_at'));
        $this->assertSame('active', Business::sole()->status);
    }

    public function test_repeated_keys_duplicate_addresses_and_unknown_places_are_reviewable(): void
    {
        $this->approve();
        $body = "one,Example Store,retail,brandon,1 Test Road,,store\n"
            ."one,Example Store,retail,brandon,2 Test Road,,store\n"
            ."three,Example Store,retail,brandon,1 Test Road,,store\n"
            ."four,Example Store,retail,unknown-place,4 Test Road,,distinct\n";
        $plan = $this->stage($body);
        $counts = $this->imports->counts($this->operator, $plan->id);
        $this->assertSame(2, $counts['duplicate_key']);
        $this->assertSame(1, $counts['physical_conflict']);
        $this->assertSame(1, $counts['invalid']);
        $this->expectRejected(fn () => $this->apply($plan), 'unresolved');
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_import_rejects_tampering_and_rolls_back_a_failed_write_before_retry(): void
    {
        $this->approve();
        $data = "one,Test Services,services,brandon,1 Test Street,,group\n"
            ."two,Test Services,services,brandon,2 Test Street,,group\n";
        $plan = $this->stage($data);
        DB::table('business_import_rows')->where(['run_id' => $plan->id, 'row_number' => 2])->update(['decision' => 'skipped']);
        $this->expectRejected(fn () => $this->apply($plan), 'changed');

        $retryable = $this->stage($data);
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inserts): void {
            if (preg_match('/^insert into [`"]business_locations[`"]/i', $sql) && ++$inserts === 2) {
                throw new RuntimeException('Simulated interruption during the second branch insert.');
            }
        });
        $this->expectRejected(fn () => $this->apply($retryable), 'Simulated interruption');
        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('business_locations', 0);
        $this->assertDatabaseCount('business_import_identities', 0);
        $this->assertDatabaseHas('business_import_runs', ['id' => $retryable->id, 'status' => 'staged']);
        $this->apply($retryable);
        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseCount('business_locations', 2);
    }

    public function test_explicit_skip_cannot_mark_a_snapshot_complete_and_expired_plan_cannot_apply(): void
    {
        $this->approve();
        $data = "one,Test Services,services,brandon,1 Test Street,,group\n"
            ."two,Test Services,services,missing,2 Test Street,,group\n";
        $snapshot = $this->stage($data, true);
        $skip = fn ($plan) => $this->imports->resolve($this->operator, $plan->id, $plan->review_token, 3, 'skip', null, 'The location cannot be independently confirmed.');
        $this->expectRejected(fn () => $skip($snapshot), 'incremental');
        $incremental = $this->stage($data);
        $skip($incremental);
        $this->apply(DB::table('business_import_runs')->find($incremental->id));
        $this->assertDatabaseCount('business_locations', 1);
        $this->travel(3)->days();
        $this->expectRejected(fn () => $this->apply($snapshot), 'expired');
        $this->assertSame(4, $this->imports->purge($this->operator));
        $this->assertDatabaseCount('business_import_rows', 4);
    }

    private function approve(): void
    {
        app(BusinessSources::class)->approve($this->operator, $this->source, 1, 'Reviewed synthetic rights and use terms.');
    }

    private function stage(string $body, bool $snapshot = false): object
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, "external_key,name,category,location,address,website,business_key\n".$body);
            rewind($stream);

            return $this->imports->stage($this->operator, $this->source, $stream, $snapshot);
        } finally {
            fclose($stream);
        }
    }

    private function apply(object $plan): void
    {
        $this->imports->apply($this->operator, $plan->id, $plan->review_token, 'Reviewed this synthetic input and its plan.');
    }

    private function expectRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe import was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
