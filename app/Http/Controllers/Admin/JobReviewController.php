<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\JobPosting;
use App\Support\Audit;
use App\Support\JobBoard;
use App\Support\ModerationDecision;
use App\Support\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobReviewController extends Controller
{
    public function index(JobBoard $board)
    {
        $items = $board->scope(null)->whereIn('status', ['pending', 'approved'])->latest('id')->paginate(20);

        return view('admin.jobs', compact('items'));
    }

    public function update(Request $request, int $job, JobBoard $board)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject', 'reason' => 'required_if:action,reject|nullable|string|max:300',
            'version' => ModerationDecision::VERSION_RULES, 'notes' => 'nullable|string|max:1200',
        ]);
        DB::transaction(function () use ($request, $job, $board, $data) {
            $initial = JobPosting::findOrFail($job);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $branch = BusinessLocation::where('business_id', $business->id)->lockForUpdate()->findOrFail($initial->business_location_id);
            $item = JobPosting::where('business_location_id', $branch->id)->lockForUpdate()->findOrFail($job);
            ModerationDecision::requireCurrent('job', $item, $data['version']);
            abort_if($request->attributes->get('bulk_moderation') && $item->status !== 'pending', 409);
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending' && $business->status === 'active'
                    && $item->closing_on->toDateString() >= $board->closingToday(), 409);
                $item->forceFill(['status' => 'approved', 'published_at' => $item->published_at ?? now()])->save();
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
                $item->forceFill(['status' => 'rejected'])->save();
            }
            $audit = Audit::record('jobs.'.$data['action'], $request->user()->id, context: ['job' => $job]);
            ModerationDecision::record('job', $job, $data['action'], $audit, $data, $audit);
            if ($item->author_id !== null) {
                NotificationInbox::record($item->author_id, 'job.'.$item->status, $audit, $job, [$branch->location_id]);
            }
        }, attempts: 3);

        return redirect('/admin/jobs')->with('status', 'Job moderation saved.');
    }
}
