<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Audit;
use App\Support\CommercialRecords;
use App\Support\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessEntitlementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $filters = $request->validate(['status' => 'nullable|in:active,expired,revoked,scheduled', 'q' => 'nullable|string|max:100',
            'business_q' => 'nullable|string|max:100']);
        $id = $request->user()->id;
        $businesses = Business::where('status', 'active')->whereDoesntHave('members', fn ($query) => $query->where('users.id', $id))
            ->when(filled($filters['business_q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['business_q'], ['name']))
            ->orderBy('name')->orderBy('id')->paginate(50, ['id', 'name'], 'business_page')->withQueryString();
        $today = today('America/Winnipeg')->toDateString();
        $items = DB::table('business_entitlements')->join('businesses', 'businesses.id', '=', 'business_entitlements.business_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('business_memberships')->whereColumn('business_memberships.business_id', 'business_entitlements.business_id')->where('user_id', $id));
        foreach (KeywordSearch::patterns($filters['q'] ?? '') as $pattern) {
            $items->whereRaw("LOWER(businesses.name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        match ($filters['status'] ?? null) {
            'active' => $items->whereNull('revoked_at')->where('starts_on', '<=', $today)->where('ends_on', '>', $today),
            'expired' => $items->whereNull('revoked_at')->where('ends_on', '<=', $today),
            'scheduled' => $items->whereNull('revoked_at')->where('starts_on', '>', $today),
            'revoked' => $items->whereNotNull('revoked_at'),
            default => null,
        };
        $items = $items->select('business_entitlements.id', 'business_entitlements.starts_on', 'business_entitlements.ends_on', 'business_entitlements.revoked_at', 'businesses.name')
            ->latest('business_entitlements.id')->paginate(20)->withQueryString();

        return view('admin.entitlements', compact('businesses', 'items', 'today', 'filters'));
    }

    public function grant(Request $request)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $data = $request->validate(['business_id' => 'required|integer|min:1', 'days' => 'required|integer|min:1|max:90', 'note' => 'required|string|min:8|max:400']);
        DB::transaction(function () use ($request, $data) {
            $business = Business::where('status', 'active')->lockForUpdate()->findOrFail($data['business_id']);
            abort_if($business->members()->whereKey($request->user()->id)->exists(), 403);
            $today = today('America/Winnipeg');
            $end = $today->copy()->addDays((int) $data['days']);
            if (DB::table('business_entitlements')->where('business_id', $business->id)->where('product', 'business-pro')
                ->whereNull('revoked_at')->where('starts_on', '<', $end->toDateString())->where('ends_on', '>', $today->toDateString())->exists()) {
                throw ValidationException::withMessages(['business_id' => 'An overlapping trial already exists.']);
            }
            $id = DB::table('business_entitlements')->insertGetId([
                'business_id' => $business->id, 'granted_by' => $request->user()->id,
                'product' => 'business-pro', 'starts_on' => $today->toDateString(), 'ends_on' => $end->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('commercial.trial-granted', $request->user()->id, context: ['entitlement' => $id, 'business' => $business->id, 'note' => $data['note']]);
        }, attempts: 3);

        return redirect('/admin/entitlements')->with('status', 'Internal trial record granted. No payment or paid feature activated.');
    }

    public function revoke(Request $request, int $entitlement)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        DB::transaction(function () use ($request, $entitlement) {
            $record = DB::table('business_entitlements')->where('id', $entitlement)->firstOrFail();
            $business = Business::lockForUpdate()->findOrFail($record->business_id);
            abort_if($business->members()->whereKey($request->user()->id)->exists(), 403);
            $record = DB::table('business_entitlements')->where('id', $entitlement)->lockForUpdate()->firstOrFail();
            abort_if($record->revoked_at !== null, 409);
            DB::table('business_entitlements')->where('id', $entitlement)->update(['revoked_at' => now(), 'updated_at' => now()]);
            Audit::record('commercial.trial-revoked', $request->user()->id, context: ['entitlement' => $entitlement]);
        }, attempts: 3);

        return redirect('/admin/entitlements')->with('status', 'Trial record revoked.');
    }
}
