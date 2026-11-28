<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\JobPosting;
use App\Support\Audit;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobController extends Controller
{
    public function __construct(private readonly JobBoard $jobs) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        $query = $this->visible($request);
        $filters = $request->validate(JobBoard::searchRules() + ['sort' => 'nullable|in:newest,deadline,pay-high']);
        $query = $this->jobs->filter($query, $site, $filters);
        if (($filters['sort'] ?? '') === 'deadline') {
            $query->orderBy('closing_on');
        } elseif (($filters['sort'] ?? '') === 'pay-high') {
            if (! isset($filters['pay_unit'])) {
                throw ValidationException::withMessages(['pay_unit' => 'Select hourly or annual pay to compare compensation.']);
            }
            $query->orderByRaw('CASE WHEN pay_max IS NULL THEN 1 ELSE 0 END')->orderByDesc('pay_max');
        }
        $items = $query->latest('published_at')->latest('id')->paginate(18)->appends(LocationScope::preserveAll($filters));

        return view('jobs.index', compact('items', 'filters'));
    }

    public function show(Request $request, int $job)
    {
        $item = $this->visible($request)->findOrFail($job);
        $canonical = NetworkUrl::to($this->jobs->canonicalSite($item), '/jobs/'.$item->id);
        $saved = $request->user() && DB::table('job_posting_bookmarks')
            ->where(['user_id' => $request->user()->id, 'job_posting_id' => $job])->exists();

        $related = $this->jobs->visible($request->attributes->get('site')->location_id)
            ->whereKeyNot($item->id)->whereHas('branch', fn ($query) => $query->where('business_id', $item->branch->business_id))
            ->latest('published_at')->latest('id')->limit(4)->get();

        return view('jobs.show', compact('item', 'saved', 'canonical', 'related'));
    }

    public function business(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $branches = $employer->locations()->with('location')->when($site->location_id, fn ($query, $location) => $query->where('location_id', $location))->get();
        $openBranches = $branches->filter(fn ($branch) => $branch->isOpen());
        $statuses = ['pending', 'approved', 'rejected', 'expired', 'withdrawn'];
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'q' => 'nullable|string|max:100',
            'branch' => 'nullable|integer|min:1', 'reference' => 'nullable|integer|min:1',
            'type' => ['nullable', Rule::in(array_keys(JobBoard::TYPES))],
            'workplace' => ['nullable', Rule::in(array_keys(JobBoard::WORKPLACES))],
            'closing' => 'nullable|in:week,month,past', 'sort' => 'nullable|in:recent,deadline,oldest',
        ]);
        if (isset($filters['branch']) && ! $branches->contains('id', (int) $filters['branch'])) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch on this portal.']);
        }
        $owned = $this->jobs->scope($site->location_id)->whereIn('business_location_id', $branches->pluck('id'))
            ->when($filters['branch'] ?? null, fn ($query, $branch) => $query->where('business_location_id', $branch))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('employment_type', $type))
            ->when($filters['workplace'] ?? null, fn ($query, $workplace) => $query->where('workplace', $workplace))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        $today = today('America/Winnipeg');
        match ($filters['closing'] ?? null) {
            'week' => $owned->whereBetween('closing_on', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]),
            'month' => $owned->whereBetween('closing_on', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()]),
            'past' => $owned->where('closing_on', '<', $today->toDateString()),
            default => null,
        };
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        match ($filters['sort'] ?? 'recent') {
            'deadline' => $items->orderBy('closing_on')->orderBy('id'),
            'oldest' => $items->orderBy('id'),
            default => $items->latest('id'),
        };
        $items = $items->paginate(12)->appends($filters);

        return view('jobs.business', compact('employer', 'branches', 'openBranches', 'items', 'statuses', 'filters', 'counts'));
    }

    public function store(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $data = $this->postingData($request);
        $branch = $employer->openLocations()->findOrFail($data['business_location_id']);
        LocationScope::forPublication($site, $branch->location_id);
        DB::transaction(function () use ($request, $employer, $data) {
            Business::lockForUpdate()->findOrFail($employer->id);
            $current = $this->managedBusiness($request, $employer->id);
            $branch = $current->openLocations()->findOrFail($data['business_location_id']);
            LocationScope::forPublication($request->attributes->get('site'), $branch->location_id);
            if (JobPosting::where('business_location_id', $branch->id)->where('title', $data['title'])
                ->where('employment_type', $data['employment_type'])->where('workplace', $data['workplace'])
                ->where('apply_url', $data['apply_url'])->whereIn('status', ['pending', 'approved'])
                ->where('closing_on', '>=', $this->jobs->closingToday())->exists()) {
                throw ValidationException::withMessages(['title' => 'A matching open or pending posting already exists at this branch. Edit the existing posting instead.']);
            }
            $job = JobPosting::create($data + ['author_id' => $request->user()->id]);
            Audit::record('jobs.submitted', $request->user()->id, context: ['job' => $job->id]);
        });

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job submitted for review.');
    }

    public function duplicate(Request $request, int $business, int $job)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $item = $this->managedJob($request, $employer->id, $job);
        $branches = $employer->openLocations()->with('location')->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->get();
        $item->closing_on = null;

        return view('jobs.duplicate', compact('employer', 'item', 'branches'));
    }

    public function edit(Request $request, int $business, int $job)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $item = $this->managedJob($request, $employer->id, $job);
        abort_unless($item->branch->isOpen() && in_array($item->status, ['pending', 'approved', 'rejected', 'expired'], true), 409);

        return view('jobs.edit', compact('employer', 'item'));
    }

    public function update(Request $request, int $business, int $job)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        DB::transaction(function () use ($request, $employer, $job) {
            Business::lockForUpdate()->findOrFail($employer->id);
            $this->managedBusiness($request, $employer->id);
            $item = $this->managedJob($request, $employer->id, $job, lock: true);
            abort_unless($item->branch->isOpen() && in_array($item->status, ['pending', 'approved', 'rejected', 'expired'], true), 409);
            $data = $this->postingData($request, needsBranch: false);
            $version = $request->validate(['version' => 'required|string|size:64'])['version'];
            abort_unless(hash_equals($item->reviewVersion(), $version), 409, 'The posting changed. Reload the editor.');
            $renewal = $item->status === 'expired' || $item->closing_on->toDateString() < $this->jobs->closingToday();
            $item->fill($data);
            if ($item->isDirty() || in_array($item->status, ['rejected', 'expired'], true)) {
                if (JobPosting::where('business_location_id', $item->business_location_id)->whereKeyNot($item->id)
                    ->where('title', $item->title)->where('employment_type', $item->employment_type)
                    ->where('workplace', $item->workplace)->where('apply_url', $item->apply_url)
                    ->whereIn('status', ['pending', 'approved'])->where('closing_on', '>=', $this->jobs->closingToday())->exists()) {
                    throw ValidationException::withMessages(['title' => 'A matching open or pending posting already exists at this branch.']);
                }
                $item->forceFill(['status' => 'pending']);
                if ($renewal) {
                    $item->published_at = null;
                }
                $item->save();
                Audit::record('jobs.revised', $request->user()->id, context: ['job' => $job]);
            }
        });

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job saved. Changed content requires review.');
    }

    public function withdraw(Request $request, int $business, int $job)
    {
        abort_unless($this->jobs->enabled($request->attributes->get('site')), 404);
        $employer = $this->managedBusiness($request, $business);
        DB::transaction(function () use ($request, $employer, $job) {
            Business::lockForUpdate()->findOrFail($employer->id);
            $this->managedBusiness($request, $employer->id);
            $item = $this->managedJob($request, $employer->id, $job, lock: true);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
            $data = $request->validate(['version' => 'required|string|size:64']);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'The posting changed. Reload before withdrawing.');
            $item->forceFill(['status' => 'withdrawn'])->save();
            Audit::record('jobs.withdrawn', $request->user()->id, context: ['job' => $job]);
        });

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job withdrawn.');
    }

    public function extendSelected(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $this->managedBusiness($request, $business);
        $today = $this->jobs->closingToday();
        $data = $request->validate([
            'jobs' => 'required|array|list|min:1|max:10', 'jobs.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:12', 'versions.*' => 'required|string|size:64',
            'new_closing_on' => 'required|date_format:Y-m-d|after_or_equal:'.$today.'|before_or_equal:'.today('America/Winnipeg')->addYear()->toDateString(),
            'confirm_extend' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $business, $site, $data, $today) {
            Business::lockForUpdate()->findOrFail($business);
            $this->managedBusiness($request, $business);
            $items = $this->jobs->scope($site->location_id)->whereHas('branch', fn ($branch) => $branch->where('business_id', $business))
                ->whereIn('id', $data['jobs'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['jobs']), 404);
            foreach ($items as $item) {
                if (! $item->branch->isOpen() || ! in_array($item->status, ['pending', 'approved', 'expired'], true)
                    || ! isset($data['versions'][$item->id]) || ! hash_equals($item->reviewVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['jobs' => 'A selected job changed or cannot be extended. No postings changed.']);
                }
                if ($data['new_closing_on'] <= $item->closing_on->toDateString()) {
                    throw ValidationException::withMessages(['new_closing_on' => 'Choose a date later than every selected posting’s current closing date.']);
                }
            }
            foreach ($items as $item) {
                if (JobPosting::where('business_location_id', $item->business_location_id)->whereKeyNot($item->id)
                    ->where('title', $item->title)->where('employment_type', $item->employment_type)
                    ->where('workplace', $item->workplace)->where('apply_url', $item->apply_url)
                    ->whereIn('status', ['pending', 'approved'])->where('closing_on', '>=', $today)->exists()) {
                    throw ValidationException::withMessages(['jobs' => 'A matching open or pending posting already exists. No postings changed.']);
                }
                if ($item->status === 'expired' || $item->closing_on->toDateString() < $today) {
                    $item->published_at = null;
                }
                $item->forceFill(['closing_on' => $data['new_closing_on'], 'status' => 'pending'])->save();
                Audit::record('jobs.revised', $request->user()->id, context: ['job' => $item->id]);
            }
        }, attempts: 3);

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', count($data['jobs']).' job posting deadlines extended for review.');
    }

    public function withdrawSelected(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $this->managedBusiness($request, $business);
        $data = $request->validate([
            'jobs' => 'required|array|list|min:1|max:10', 'jobs.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:12', 'versions.*' => 'required|string|size:64',
            'confirm_withdraw' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $business, $site, $data) {
            Business::lockForUpdate()->findOrFail($business);
            $this->managedBusiness($request, $business);
            $items = $this->jobs->scope($site->location_id)->whereHas('branch', fn ($branch) => $branch->where('business_id', $business))
                ->whereIn('id', $data['jobs'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['jobs']), 404);
            foreach ($items as $item) {
                if (! in_array($item->status, ['pending', 'approved'], true)
                    || ! isset($data['versions'][$item->id]) || ! hash_equals($item->reviewVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['jobs' => 'A selected job changed or cannot be withdrawn. No postings changed.']);
                }
            }
            foreach ($items as $item) {
                $item->forceFill(['status' => 'withdrawn'])->save();
                Audit::record('jobs.withdrawn', $request->user()->id, context: ['job' => $item->id]);
            }
        }, attempts: 3);

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', count($data['jobs']).' job postings withdrawn.');
    }

    public function save(Request $request, int $job)
    {
        $item = $this->visible($request)->findOrFail($job);
        DB::table('job_posting_bookmarks')->insertOrIgnore(['job_posting_id' => $item->id, 'user_id' => $request->user()->id]);

        return redirect($request->input('return_to') === 'job' ? LocationScope::detailUrl('/jobs/'.$item->id) : '/account/saved-jobs')->with('status', 'Job saved.');
    }

    public function remove(Request $request, int $job)
    {
        if ($request->input('return_to') === 'job') {
            $this->visible($request)->findOrFail($job);
        }
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        DB::table('job_posting_bookmarks')->where(['job_posting_id' => $job, 'user_id' => $request->user()->id])
            ->when($site->location_id, fn ($query, $id) => $query->whereIn('job_posting_id', JobPosting::whereHas('branch', fn ($branch) => $branch->where('location_id', $id))->select('id')))
            ->delete();

        return redirect($request->input('return_to') === 'job' ? LocationScope::detailUrl('/jobs/'.$job) : '/account/saved-jobs')->with('status', 'Job removed from your saved list.');
    }

    public function saved(Request $request)
    {
        $filters = $request->validate(JobBoard::searchRules() + ['sort' => 'nullable|in:recent,closing']);
        $matching = $this->visible($request)->whereIn('id', DB::table('job_posting_bookmarks')
            ->where('user_id', $request->user()->id)->select('job_posting_id'));
        $total = (clone $matching)->count();
        $site = $request->attributes->get('site');
        if (! $site->isNetwork()) {
            unset($filters['location']);
        }
        $items = $this->jobs->filter($matching, $site, $filters);
        if (($filters['sort'] ?? 'recent') === 'closing') {
            $items->orderBy('closing_on');
        }
        $items = $items->latest('published_at')->latest('id')->paginate(18)->appends($filters);

        $unavailable = DB::table('job_posting_bookmarks as bookmarks')->join('job_postings as jobs', 'jobs.id', '=', 'bookmarks.job_posting_id')
            ->join('business_locations as branches', 'branches.id', '=', 'jobs.business_location_id')
            ->join('businesses', 'businesses.id', '=', 'branches.business_id')
            ->where('bookmarks.user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('branches.location_id', $id))
            ->where(fn ($query) => $query->where('jobs.status', '!=', 'approved')->orWhere('jobs.closing_on', '<', $this->jobs->closingToday())
                ->orWhere('businesses.status', '!=', 'active')->orWhere('branches.location_status', 'closed'))
            ->orderByDesc('bookmarks.job_posting_id')->paginate(15, ['bookmarks.job_posting_id'], 'unavailable_page')->appends($filters);
        if ($items->currentPage() > 1) {
            $unavailable->appends(['page' => $items->currentPage()]);
        }
        if ($unavailable->currentPage() > 1) {
            $items->appends(['unavailable_page' => $unavailable->currentPage()]);
        }

        return view('jobs.saved', compact('items', 'filters', 'total', 'unavailable'));
    }

    public function clearUnavailable(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $request->validate(['confirm_remove' => 'accepted']);
        $unavailable = JobPosting::query()->whereHas('branch', fn ($query) => $query->when($site->location_id, fn ($branch, $id) => $branch->where('location_id', $id)))
            ->where(fn ($query) => $query->where('status', '!=', 'approved')->orWhere('closing_on', '<', $this->jobs->closingToday())
                ->orWhereHas('branch', fn ($branch) => $branch->where('location_status', 'closed')->orWhereHas('business', fn ($business) => $business->where('status', '!=', 'active'))))
            ->select('id');
        $removed = DB::table('job_posting_bookmarks')->where('user_id', $request->user()->id)
            ->whereIn('job_posting_id', $unavailable)->delete();

        return redirect('/account/saved-jobs')->with('status', $removed.' unavailable saved jobs removed.');
    }

    private function visible(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);

        return $this->jobs->visible($site->location_id);
    }

    private function managedBusiness(Request $request, int $business): Business
    {
        return Business::where('status', 'active')->managedBy($request->user()->id, ['owner', 'manager'])->findOrFail($business);
    }

    private function managedJob(Request $request, int $business, int $job, bool $lock = false): JobPosting
    {
        return $this->jobs->scope($request->attributes->get('site')->location_id)
            ->whereHas('branch', fn ($branch) => $branch->where('business_id', $business))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($job);
    }

    private function postingData(Request $request, bool $needsBranch = true): array
    {
        $today = $this->jobs->closingToday();
        $rules = [
            'title' => 'required|string|max:140', 'description' => 'required|string|min:20|max:6000',
            'employment_type' => ['required', Rule::in(array_keys(JobBoard::TYPES))],
            'workplace' => ['required', Rule::in(array_keys(JobBoard::WORKPLACES))],
            'apply_url' => 'required|url:https|max:1024', 'closing_on' => 'required|date_format:Y-m-d|after_or_equal:'.$today.'|before_or_equal:'.today('America/Winnipeg')->addYear()->toDateString(),
            'pay_unit' => 'required_with:pay_min,pay_max|nullable|in:hour,year',
            'pay_min' => 'required_with:pay_unit,pay_max|nullable|numeric|decimal:0,2|min:0|max:10000000',
            'pay_max' => 'required_with:pay_unit,pay_min|nullable|numeric|decimal:0,2|gte:pay_min|max:10000000',
        ];
        if ($needsBranch) {
            $rules['business_location_id'] = 'required|integer';
        }
        $data = $request->validate($rules);
        $data['pay_unit'] ??= null;
        foreach (['pay_min', 'pay_max'] as $field) {
            $data[$field] = isset($data[$field]) ? (int) round($data[$field] * 100) : null;
        }

        return $data;
    }
}
