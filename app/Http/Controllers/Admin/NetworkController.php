<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\Marketplace;
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
        return view('admin.moderation', ['items' => Listing::with(['location', 'seller', 'categoryRecord'])
            ->whereIn('status', ['pending', 'active'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('updated_at')->paginate(25), 'reports' => DB::table('listing_reports')
            ->join('listings', 'listings.id', '=', 'listing_reports.listing_id')
            ->whereNull('resolved_at')->latest('listing_reports.id')
            ->paginate(15, ['listing_reports.*', 'listings.title'], 'reports_page')]);
    }

    public function resolveReport(Request $request, int $report)
    {
        $data = $request->validate(['note' => 'required|string|max:240']);
        DB::transaction(function () use ($request, $report, $data) {
            abort_unless(DB::table('listing_reports')->where('id', $report)->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]), 404);
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
        ]);

        DB::transaction(function () use ($request, $listing, $data, $marketplace) {
            $item = Listing::lockForUpdate()->findOrFail($listing);
            abort_unless(in_array($item->status, ['pending', 'active'], true), 409);
            abort_if($data['status'] === 'active' && $item->status !== 'pending', 409);
            $marketplace->changeStatus($item, $data['status'], $request->user()->id, $data['reason'] ?? null, moderated: true);
            Audit::record('listing.'.$data['status'], $request->user()->id, context: ['listing' => $listing]);
        });

        return redirect('/admin/moderation')->with('status', 'Moderation action saved.');
    }
}
