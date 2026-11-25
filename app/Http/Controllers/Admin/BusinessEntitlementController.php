<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Audit;
use App\Support\CommercialRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessEntitlementController extends Controller
{
    public function index()
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $businesses = Business::where('status', 'active')->orderBy('name')->limit(100)->get(['id', 'name']);
        $items = DB::table('business_entitlements')->join('businesses', 'businesses.id', '=', 'business_entitlements.business_id')
            ->select('business_entitlements.*', 'businesses.name')->latest('business_entitlements.id')->paginate(20);
        $today = today('America/Winnipeg')->toDateString();

        return view('admin.entitlements', compact('businesses', 'items', 'today'));
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
