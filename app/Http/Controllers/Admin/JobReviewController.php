<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Location;
use App\Support\Audit;
use App\Support\OwnerNotifications;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobReviewController extends Controller
{
    public function index(Request $request, JobBoard $board)
    {
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,expired,withdrawn', 'q' => 'nullable|string|max:100', 'location' => 'nullable|integer|exists:locations,id']);
        $matching = $board->scope(isset($filters['location']) ? (int) $filters['location'] : null)->whereIn('status', ['pending', 'approved', 'rejected', 'expired', 'withdrawn'])
            ->whereHas('branch.business', fn ($query) => $query->whereDoesntHave('members', fn ($members) => $members->whereKey($request->user()->id)))
            ->where(fn ($query) => $query->whereNull('author_id')->orWhere('author_id', '!=', $request->user()->id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $matching->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")->latest('id')->paginate(20)->withQueryString();

        $places = Location::selectable()->orderBy('name')->get(['id', 'name']);

        return view('admin.jobs', compact('items', 'filters', 'counts', 'places'));
    }

    public function updateSelected(Request $request, JobBoard $board)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:300',
            'jobs' => 'required|array|list|min:1|max:10',
            'jobs.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20',
            'versions.*' => 'required|string|size:64|alpha_num',
            'confirm_review' => 'accepted',
        ]);
        $reviewer = $request->user()->id;
        DB::transaction(function () use ($data, $board, $reviewer) {
            $initial = JobPosting::with('branch')->whereIn('id', $data['jobs'])->get();
            abort_unless($initial->count() === count($data['jobs']), 404);
            $businessIds = $initial->map(fn ($job) => $job->branch->business_id)->unique()->sort()->values();
            $businesses = Business::whereIn('id', $businessIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($businesses->count() === $businessIds->count(), 409);
            $items = JobPosting::with('branch')->whereIn('id', $data['jobs'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['jobs']), 404);
            foreach ($items as $item) {
                $business = $businesses->get($item->branch->business_id);
                if (! $business || ! isset($data['versions'][$item->id]) || ! hash_equals($item->reviewVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['jobs' => 'A selected posting changed. No review actions were saved.']);
                }
                abort_if($item->author_id === $reviewer || $business->members()->whereKey($reviewer)->exists(), 403);
                $eligible = $data['action'] === 'approve'
                    ? $item->status === 'pending' && $item->branch->isOpen() && $business->status === 'active'
                        && $item->closing_on->toDateString() >= $board->closingToday()
                    : in_array($item->status, ['pending', 'approved'], true);
                if (! $eligible) {
                    throw ValidationException::withMessages(['jobs' => 'A selected posting is ineligible for this action. No review actions were saved.']);
                }
            }
            foreach ($items as $item) {
                if ($data['action'] === 'approve') {
                    $item->forceFill(['status' => 'approved', 'published_at' => $item->published_at ?? now()])->save();
                } else {
                    $item->forceFill(['status' => 'rejected'])->save();
                }
                OwnerNotifications::record($item->author_id, 'job', $item->id, $item->status);
                Audit::record('jobs.'.$data['action'], $reviewer, context: ['job' => $item->id, 'reason' => $data['reason'] ?? null, 'batch' => true]);
            }
        }, attempts: 3);

        return redirect('/admin/jobs')->with('status', count($data['jobs']).' selected job postings moderated.');
    }

    public function update(Request $request, int $job, JobBoard $board)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject', 'reason' => 'required_if:action,reject|nullable|string|max:300',
            'version' => 'required|size:64|alpha_num',
        ]);
        DB::transaction(function () use ($request, $job, $board, $data) {
            $initial = JobPosting::with('branch')->findOrFail($job);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $item = JobPosting::with('branch.business')->lockForUpdate()->findOrFail($job);
            abort_if($item->author_id === $request->user()->id || $business->members()->whereKey($request->user()->id)->exists(), 403);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'The posting changed. Reload it before reviewing.');
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending' && $item->branch->isOpen() && $item->branch->business->status === 'active'
                    && $item->closing_on->toDateString() >= $board->closingToday(), 409);
                $item->forceFill(['status' => 'approved', 'published_at' => $item->published_at ?? now()])->save();
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
                $item->forceFill(['status' => 'rejected'])->save();
            }
            OwnerNotifications::record($item->author_id, 'job', $item->id, $item->status);
            Audit::record('jobs.'.$data['action'], $request->user()->id, context: ['job' => $job, 'reason' => $data['reason'] ?? null]);
        });

        return redirect('/admin/jobs')->with('status', 'Job moderation saved.');
    }
}
