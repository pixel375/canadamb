<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\Audit;
use App\Support\BusinessInquirySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessInquirySettingsController extends Controller
{
    public function update(Request $request, int $business)
    {
        abort_unless(BusinessInquirySettings::enabled(), 404);
        $data = $request->validate(['business_inquiries' => 'required|boolean', 'job_inquiries' => 'required|boolean']);
        DB::transaction(function () use ($request, $business, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            $id = $request->user()->id;
            abort_unless($item->status === 'active' && $item->members()->wherePivot('role', 'owner')->count() === 1
                && $item->members()->whereKey($id)->wherePivot('role', 'owner')->exists(), 404);
            DB::table('business_inquiry_settings')->updateOrInsert(['business_id' => $business], [
                'owner_id' => $id, 'business_inquiries' => (bool) $data['business_inquiries'],
                'job_inquiries' => (bool) $data['job_inquiries'], 'updated_at' => now(),
            ]);
            Audit::record('business.inquiry-preferences', $id, context: ['business' => $business]);
        }, attempts: 3);

        return redirect('/account/businesses/'.$business)->with('status', 'Inquiry preferences saved.');
    }
}
