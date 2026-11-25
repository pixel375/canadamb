<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use App\Support\BusinessDisputes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessDisputeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        $items = $this->records()->where('claimant_id', $request->user()->id)
            ->latest('business_disputes.id')->paginate(20);

        return view('business.disputes', compact('items'));
    }

    public function create(Request $request, string $slug)
    {
        $item = $this->visible($request, $slug)->firstOrFail();
        abort_unless($item->members()->wherePivot('role', 'owner')->exists(), 404);

        return view('business.dispute-form', compact('item'));
    }

    public function store(Request $request, string $slug)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        $data = $request->validate(['contact_email' => 'required|email|max:254', 'explanation' => 'required|string|min:30|max:2000']);
        DB::transaction(function () use ($request, $slug, $data) {
            $item = $this->visible($request, $slug)->lockForUpdate()->firstOrFail();
            abort_unless($item->members()->wherePivot('role', 'owner')->exists(), 404);
            $id = $request->user()->id;
            $pending = DB::table('business_disputes')->where('business_id', $item->id)
                ->where('claimant_id', $id)->where('status', 'pending')->exists();
            if ($pending) {
                throw ValidationException::withMessages(['explanation' => 'You already have an open dispute for this business.']);
            }
            $dispute = DB::table('business_disputes')->insertGetId($data + [
                'business_id' => $item->id, 'claimant_id' => $id, 'site_id' => $request->attributes->get('site')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.dispute-submitted', $id, context: ['business' => $item->id, 'dispute' => $dispute]);
        }, attempts: 3);

        return redirect('/account/business-disputes')->with('status', 'Dispute submitted for independent review.');
    }

    public function withdraw(Request $request, int $dispute)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        DB::transaction(function () use ($request, $dispute) {
            $id = $request->user()->id;
            $row = DB::table('business_disputes')->where('claimant_id', $id)->where('id', $dispute)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'pending', 409);
            DB::table('business_disputes')->where('id', $dispute)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            Audit::record('business.dispute-withdrawn', $id, context: ['dispute' => $dispute]);
        }, attempts: 3);

        return redirect('/account/business-disputes')->with('status', 'Dispute withdrawn.');
    }

    public function queue(Request $request)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        $id = $request->user()->id;
        $items = $this->records()
            ->where('business_disputes.status', 'pending')
            ->where('claimant_id', '!=', $id)
            ->whereNotExists(function ($query) use ($id) {
                $query->selectRaw('1')->from('business_memberships')
                    ->whereColumn('business_memberships.business_id', 'business_disputes.business_id')
                    ->where('user_id', $id);
            })
            ->latest('business_disputes.id')->paginate(20);

        return view('admin.business-disputes', compact('items'));
    }

    public function review(Request $request, int $dispute)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        $data = $request->validate(['decision' => 'required|in:closed,escalated', 'note' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($request, $dispute, $data) {
            $initial = DB::table('business_disputes')->where('id', $dispute)->firstOrFail();
            $business = Business::lockForUpdate()->findOrFail($initial->business_id);
            $row = DB::table('business_disputes')->where('id', $dispute)->lockForUpdate()->firstOrFail();
            $id = $request->user()->id;
            abort_if((int) $row->claimant_id === $id || $business->members()->whereKey($id)->exists(), 403);
            abort_unless($row->status === 'pending' && $row->claimant_id, 409);
            DB::table('business_disputes')->where('id', $dispute)->update([
                'status' => $data['decision'], 'review_note' => $data['note'], 'reviewer_id' => $id,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.dispute-'.$data['decision'], $id, context: ['dispute' => $dispute, 'business' => $business->id]);
        }, attempts: 3);

        return redirect('/admin/business-disputes')->with('status', 'Review recorded. Business ownership is unchanged.');
    }

    private function visible(Request $request, string $slug)
    {
        abort_unless(BusinessDisputes::enabled(), 404);
        $site = $request->attributes->get('site');
        $directory = app(BusinessDirectory::class);
        abort_unless($directory->enabled($site), 404);

        return $directory->visible($site)->where('slug', $slug);
    }

    private function records()
    {
        return DB::table('business_disputes')->join('businesses', 'businesses.id', '=', 'business_disputes.business_id')
            ->select('business_disputes.*', 'businesses.name as business_name');
    }
}
