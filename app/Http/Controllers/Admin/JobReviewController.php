<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Support\Audit;
use App\Support\JobBoard;
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
            'version' => 'required|size:64|alpha_num',
        ]);
        DB::transaction(function () use ($request, $job, $board, $data) {
            $item = JobPosting::with('branch.business')->lockForUpdate()->findOrFail($job);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'The posting changed. Reload it before reviewing.');
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending' && $item->branch->business->status === 'active'
                    && $item->closing_on->toDateString() >= $board->closingToday(), 409);
                $item->forceFill(['status' => 'approved', 'published_at' => $item->published_at ?? now()])->save();
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
                $item->forceFill(['status' => 'rejected'])->save();
            }
            Audit::record('jobs.'.$data['action'], $request->user()->id, context: ['job' => $job, 'reason' => $data['reason'] ?? null]);
        });

        return redirect('/admin/jobs')->with('status', 'Job moderation saved.');
    }
}
