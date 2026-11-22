<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\ListingPhotoOrder;
use App\Support\Marketplace;
use App\Support\ModerationDecision;
use App\Support\ModerationReports;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NetworkController extends Controller
{
    public function index(NetworkSettings $settings)
    {
        return view('admin.index', [
            'sites' => Site::count(), 'active' => Site::where('status', 'active')->count(),
            'locations' => Location::selectable()->count(), 'accounts' => User::count(),
            'pending' => Listing::where('status', 'pending')->count(), 'version' => $settings->state()->version,
        ]);
    }

    public function audit()
    {
        return view('admin.audit', ['entries' => DB::table('audit_logs')->select('audit_logs.*', 'users.name')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')->latest('audit_logs.id')->paginate(25)]);
    }

    public function moderation()
    {
        return view('admin.moderation', ['items' => Listing::with(['location', 'seller', 'categoryRecord', 'media'])
            ->whereIn('status', ['pending', 'active'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('updated_at')->paginate(25), 'reports' => DB::table('listing_reports')
            ->join('listings', 'listings.id', '=', 'listing_reports.listing_id')
            ->whereNull('resolved_at')->latest('listing_reports.id')
            ->paginate(15, ['listing_reports.*', 'listings.title'], 'reports_page')]);
    }

    public function resolveReport(Request $request, int $report, ModerationReports $reports)
    {
        $data = $request->validate(['note' => 'required|string|max:240', 'version' => ModerationReports::VERSION_RULE]);
        $reports->decide($request->user()->id, 'listing', $report, $data['version'], 'resolved', $data['note']);

        return redirect('/admin/moderation/marketplace')->with('status', 'Listing report resolved.');
    }

    public function moderate(Request $request, int $listing, Marketplace $marketplace)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'rejected', 'removed'])],
            'reason' => ['required_unless:status,active', 'nullable', 'string', 'max:240'],
            'version' => ModerationDecision::VERSION_RULES,
            'notes' => 'nullable|string|max:1200',
        ]);

        DB::transaction(function () use ($request, $listing, $data, $marketplace) {
            $item = Listing::lockForUpdate()->findOrFail($listing);
            abort_unless(in_array($item->status, ['pending', 'active'], true), 409);
            abort_if($data['status'] === 'active' && $item->status !== 'pending', 409);
            abort_if(! hash_equals(ListingPhotoOrder::version($item), $data['version']), 409,
                'This listing changed after the review screen opened. Reload it before making a decision.');
            abort_if($request->attributes->get('bulk_moderation') && ($item->status !== 'pending' || $item->media->isNotEmpty()),
                409, 'Only pending listings without photos can be reviewed in bulk.');
            $history = $marketplace->changeStatus($item, $data['status'], $request->user()->id, $data['reason'] ?? null, moderated: true);
            $audit = Audit::record('listing.'.$data['status'], $request->user()->id, context: ['listing' => $listing]);
            $action = match ($data['status']) {
                'active' => 'approve',
                'rejected' => 'reject',
                'removed' => 'remove',
            };
            ModerationDecision::record('listing', $listing, $action, $audit, $data, $history);
        }, attempts: 3);

        return redirect('/admin/moderation')->with('status', 'Moderation action saved.');
    }
}
