<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\Audit;
use App\Support\BusinessInquirySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessInquirySettingsController extends Controller
{
    public function update(Request $request, int $business)
    {
        abort_unless(BusinessInquirySettings::enabled(), 404);
        DB::transaction(function () use ($request, $business) {
            $item = Business::lockForUpdate()->findOrFail($business);
            $id = $request->user()->id;
            abort_unless($item->status === 'active' && $item->members()->wherePivot('role', 'owner')->count() === 1
                && $item->members()->whereKey($id)->wherePivot('role', 'owner')->exists(), 404);
            $data = $request->validate(['business_inquiries' => 'required|boolean', 'job_inquiries' => 'required|boolean', 'version' => 'required|string|size:64']);
            $current = BusinessInquirySettings::preferences($item, $id);
            if (! hash_equals(BusinessInquirySettings::version($item, $id, $current), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Inquiry preferences changed. Reload before saving.']);
            }
            $next = ['business_inquiries' => (bool) $data['business_inquiries'], 'job_inquiries' => (bool) $data['job_inquiries']];
            if ($next === $current) {
                return;
            }
            DB::table('business_inquiry_settings')->updateOrInsert(['business_id' => $business], $next + [
                'owner_id' => $id, 'updated_at' => now(),
            ]);
            Audit::record('business.inquiry-preferences', $id, context: ['business' => $business]);
        }, attempts: 3);

        return redirect('/account/businesses/'.$business)->with('status', 'Inquiry preferences saved.');
    }
}
