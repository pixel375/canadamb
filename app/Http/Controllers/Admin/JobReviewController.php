<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Location;
use App\Support\Audit;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            Audit::record('jobs.'.$data['action'], $request->user()->id, context: ['job' => $job, 'reason' => $data['reason'] ?? null]);
        });

        return redirect('/admin/jobs')->with('status', 'Job moderation saved.');
    }
}
