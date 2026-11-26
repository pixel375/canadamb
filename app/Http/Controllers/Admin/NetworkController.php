<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Location;
use App\Models\PropertyListing;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessAccess;
use App\Support\BusinessChanges;
use App\Support\BusinessDisputes;
use App\Support\CommercialRecords;
use App\Support\ContentReports;
use App\Support\KeywordSearch;
use App\Support\Marketplace;
use App\Support\QueueInventory;
use App\Support\NetworkSettings;
use App\Support\NetworkUrl;
use App\Support\PrivacyRequests;
use App\Support\StagedFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NetworkController extends Controller
{
    public function index(NetworkSettings $settings)
    {
        $queues = [
            ['label' => 'Marketplace listings', 'count' => Listing::where('status', 'pending')->count(), 'url' => '/admin/moderation?status=pending', 'icon' => 'marketplace'],
            ['label' => 'Open listing reports', 'count' => DB::table('listing_reports')->whereNull('resolved_at')->count(), 'url' => '/admin/moderation#reports', 'icon' => 'shield'],
            ['label' => 'Discussions', 'count' => DB::table('community_threads')->where('status', 'pending')->count(), 'url' => '/admin/community?status=pending#threads', 'icon' => 'discussions'],
            ['label' => 'Replies', 'count' => DB::table('community_replies')->where('status', 'pending')->whereExists(fn ($query) => $query->selectRaw('1')->from('community_threads')->whereColumn('community_threads.id', 'community_replies.thread_id')->where('community_threads.status', 'approved'))->count(), 'url' => '/admin/community?status=pending#replies', 'icon' => 'messages'],
            ['label' => 'Events', 'count' => DB::table('community_events')->where('status', 'pending')->count(), 'url' => '/admin/community?status=pending#events', 'icon' => 'calendar'],
            ['label' => 'Job postings', 'count' => DB::table('job_postings')->where('status', 'pending')->count(), 'url' => '/admin/jobs?status=pending', 'icon' => 'briefcase'],
            ['label' => 'Business profiles', 'count' => DB::table('businesses')->where('status', 'pending')->count(), 'url' => '/admin/business-review#profiles', 'icon' => 'building'],
            ['label' => 'Ownership claims', 'count' => DB::table('business_claims')->where('status', 'pending')->count(), 'url' => '/admin/business-review#claims', 'icon' => 'person'],
            ['label' => 'Business updates', 'count' => DB::table('business_updates')->where('status', 'pending')->count(), 'url' => '/admin/business-review#updates', 'icon' => 'news'],
        ];
        $reviewer = auth()->id();
        if (config('network.property_drafts')) {
            $queues[] = ['label' => 'Private property reviews', 'count' => PropertyListing::where('status', 'submitted')->where('owner_id', '!=', $reviewer)->count(), 'url' => '/admin/properties', 'icon' => 'house'];
        }
        if (ContentReports::enabled()) {
            $queues[] = ['label' => 'Content reports', 'count' => ContentReports::reviewQueue($reviewer)->where('status', 'pending')->count(), 'url' => '/admin/content-reports?status=pending', 'icon' => 'shield'];
        }
        foreach ([
            [BusinessChanges::enabled(), 'business_change_requests', 'requester_id', ['pending'], 'Branch and contact changes', '/admin/business-changes', 'building'],
            [BusinessDisputes::enabled(), 'business_disputes', 'claimant_id', ['pending', 'escalated'], 'Ownership disputes', '/admin/business-disputes', 'person'],
            [BusinessAccess::enabled(), 'business_access_requests', 'requester_id', ['accepted'], 'Ownership transfers', '/admin/business-transfers', 'person'],
        ] as [$enabled, $table, $actor, $statuses, $label, $url, $icon]) {
            if (! $enabled) {
                continue;
            }
            $query = DB::table($table)->whereIn('status', $statuses)->where($actor, '!=', $reviewer)
                ->whereNotExists(fn ($members) => $members->selectRaw('1')->from('business_memberships')
                    ->whereColumn('business_memberships.business_id', $table.'.business_id')->where('user_id', $reviewer));
            if ($table === 'business_access_requests') {
                $query->where('kind', 'transfer')->where('recipient_id', '!=', $reviewer)->where('expires_at', '>', now());
            }
            $queues[] = ['label' => $label, 'count' => $query->count(), 'url' => $url, 'icon' => $icon];
        }
        if (auth()->user()->can('manage-theme')) {
            if (PrivacyRequests::enabled()) {
                $queues[] = ['label' => 'Privacy requests', 'count' => DB::table('account_privacy_requests')->whereIn('status', ['pending', 'in-review'])->where('user_id', '!=', $reviewer)->count(), 'url' => '/admin/privacy-requests', 'icon' => 'shield'];
            }
            if (CommercialRecords::enabled()) {
                $queues[] = ['label' => 'Advertising reviews', 'count' => DB::table('advertising_campaigns')->where('status', 'pending')
                    ->where(fn ($query) => $query->whereNull('author_id')->orWhere('author_id', '!=', $reviewer))
                    ->whereNotExists(fn ($members) => $members->selectRaw('1')->from('business_memberships')
                        ->whereColumn('business_memberships.business_id', 'advertising_campaigns.business_id')->where('user_id', $reviewer))->count(),
                    'url' => '/admin/advertising?status=pending', 'icon' => 'news'];
            }
        }
        if (auth()->user()->can('manage-news')) {
            $queues[] = ['label' => 'News drafts', 'count' => DB::table('news_articles')->where('status', 'draft')->count(), 'url' => '/admin/news#editorial-queue', 'icon' => 'news'];
        }

        return view('admin.index', [
            'queues' => $queues,
            'prelaunch' => (bool) config('network.prelaunch'),
            'recentAudit' => DB::table('audit_logs')->select('audit_logs.action', 'audit_logs.created_at', 'users.name')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')->latest('audit_logs.id')->limit(6)->get(),
            'sites' => Site::count(), 'active' => Site::where('status', 'active')->count(),
            'locations' => Location::selectable()->count(), 'accounts' => User::count(),
            'pending' => Listing::where('status', 'pending')->count(), 'version' => $settings->state()->version,
        ]);
    }

    public function readiness(NetworkSettings $settings)
    {
        $prelaunch = (bool) config('network.prelaunch');
        $checks = [
            ['label' => 'Prelaunch access configuration', 'value' => $prelaunch ? 'Enabled' : 'Disabled', 'note' => 'Configuration only; use external HTTP checks to verify enforcement.'],
            ['label' => 'Approved IP entries', 'value' => count(config('network.prelaunch_ips', [])), 'note' => 'Count only; individual IP addresses are not displayed.'],
            ['label' => 'Registration', 'value' => $settings->registrationAllowed() ? 'Available' : 'Disabled', 'note' => 'During private testing, registration should remain intentionally controlled.'],
            ['label' => 'Community sign-in', 'value' => config('network.sso_enabled') ? 'Enabled' : 'Disabled', 'note' => 'Verify a real HTTPS round trip on participating domains.'],
            ['label' => 'Scheduled queue draining', 'value' => config('network.cron_queue_drain') ? 'Configured' : 'Disabled', 'note' => 'Does not establish that the host scheduler is running.'],
            ['label' => 'News polling', 'value' => $prelaunch ? 'Suppressed during prelaunch' : (config('network.news_polling') ? 'Configured' : 'Disabled'), 'note' => 'Verify real publisher feed access before enabling scheduled ingestion.'],
            ['label' => 'Private property drafts', 'value' => config('network.property_drafts') ? 'Enabled' : 'Disabled', 'note' => 'Private review is not publication permission.'],
            ['label' => 'Public property advertisements', 'value' => config('network.real_estate_public_listings') ? 'Enabled' : 'Disabled', 'note' => 'Keep disabled until ownership and publication requirements are approved.'],
            ['label' => 'Central contact address', 'value' => NetworkUrl::main()->contact_email ? 'Configured' : 'Missing', 'note' => 'Configuration does not verify mailbox delivery or monitoring.'],
            ['label' => 'Application mail', 'value' => $prelaunch ? 'Suppressed during prelaunch' : 'Delivery requires verification', 'note' => 'Test verification and password reset delivery before public launch.'],
        ];
        $unverified = User::whereNull('email_verified_at')->count();

        $staged = StagedFeatures::inspect();

        $queue = app(QueueInventory::class)->inspect();

        return view('admin.readiness', compact('checks', 'unverified', 'staged', 'queue'));
    }

    public function audit(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'period' => 'nullable|in:7,30,90']);
        $entries = DB::table('audit_logs')->select('audit_logs.*', 'users.name')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->when($filters['q'] ?? null, function ($query, $term) {
                foreach (KeywordSearch::patterns($term) as $pattern) {
                    $query->where(fn ($match) => $match->whereRaw("LOWER(audit_logs.action) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(users.name) LIKE ? ESCAPE '!'", [$pattern]));
                }
            })
            ->when($filters['period'] ?? null, fn ($query, $days) => $query->where('audit_logs.created_at', '>=', now()->subDays((int) $days)))
            ->latest('audit_logs.id')->paginate(25)->withQueryString();

        return view('admin.audit', compact('entries', 'filters'));
    }

    public function moderation(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:pending,active,rejected,expired,sold,removed', 'q' => 'nullable|string|max:100',
            'location' => 'nullable|integer|exists:locations,id', 'report_status' => 'nullable|in:pending,resolved', 'reference' => 'nullable|integer|min:1']);
        $matching = Listing::whereIn('status', ['pending', 'active', 'rejected', 'expired', 'sold', 'removed'])->where('user_id', '!=', $request->user()->id)
            ->when($filters['location'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.moderation', ['filters' => $filters, 'counts' => $counts, 'places' => Location::selectable()->orderBy('name')->get(['id', 'name']), 'items' => $matching->with(['location', 'seller', 'categoryRecord', 'media'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'active' THEN 1 ELSE 2 END")
            ->latest('updated_at')->latest('id')->paginate(25)->withQueryString(), 'reports' => DB::table('listing_reports')
            ->join('listings', 'listings.id', '=', 'listing_reports.listing_id')
            ->when(($filters['report_status'] ?? 'pending') === 'resolved', fn ($query) => $query->whereNotNull('listing_reports.resolved_at'), fn ($query) => $query->whereNull('listing_reports.resolved_at'))
            ->when($filters['location'] ?? null, fn ($query, $id) => $query->where('listings.location_id', $id))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->where('listing_reports.id', $id))
            ->where('listings.user_id', '!=', $request->user()->id)
            ->where('listing_reports.reporter_id', '!=', $request->user()->id)->latest('listing_reports.id')
            ->paginate(15, ['listing_reports.id', 'listing_reports.reason', 'listing_reports.resolved_at', 'listing_reports.created_at', 'listings.title'], 'reports_page')->withQueryString()]);
    }

    public function resolveReport(Request $request, int $report)
    {
        $data = $request->validate(['note' => 'required|string|max:240']);
        DB::transaction(function () use ($request, $report, $data) {
            $initial = DB::table('listing_reports')->where('id', $report)->firstOrFail();
            $listing = Listing::lockForUpdate()->findOrFail($initial->listing_id);
            $row = DB::table('listing_reports')->where('id', $report)->lockForUpdate()->firstOrFail();
            abort_if($listing->user_id === $request->user()->id || (int) $row->reporter_id === $request->user()->id, 403);
            abort_unless($row->resolved_at === null, 409);
            DB::table('listing_reports')->where('id', $report)->update(['resolved_at' => now(), 'updated_at' => now()]);
            Audit::record('listing.report-resolved', $request->user()->id, context: [
                'report' => $report, 'note' => $data['note'],
            ]);
        });

        return redirect('/admin/moderation')->with('status', 'Listing report resolved.');
    }

    public function moderate(Request $request, int $listing, Marketplace $marketplace)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'rejected', 'removed'])],
            'reason' => ['required_unless:status,active', 'nullable', 'string', 'max:240'],
            'version' => 'required|string|size:64',
        ]);

        DB::transaction(function () use ($request, $listing, $data, $marketplace) {
            $item = Listing::lockForUpdate()->findOrFail($listing);
            abort_if($item->user_id === $request->user()->id, 403);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'Listing changed. Reload before reviewing.');
            abort_unless(in_array($item->status, ['pending', 'active'], true), 409);
            abort_if(in_array($data['status'], ['active', 'rejected'], true) && $item->status !== 'pending', 409);
            $marketplace->changeStatus($item, $data['status'], $request->user()->id, $data['reason'] ?? null);
            Audit::record('listing.'.$data['status'], $request->user()->id, context: ['listing' => $listing]);
        });

        return redirect('/admin/moderation')->with('status', 'Moderation action saved.');
    }
}
