<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\JobPosting;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function __construct(private readonly JobBoard $jobs) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        $query = $this->visible($request);
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'type' => ['nullable', Rule::in(array_keys(JobBoard::TYPES))],
            'workplace' => ['nullable', Rule::in(array_keys(JobBoard::WORKPLACES))], 'location' => 'nullable|string|max:150',
            'pay_unit' => 'required_with:minimum_pay|nullable|in:hour,year', 'minimum_pay' => 'nullable|numeric|decimal:0,2|min:0|max:10000000',
        ]);
        $items = $query
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'description']))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('employment_type', $type))
            ->when($filters['workplace'] ?? null, fn ($query, $workplace) => $query->where('workplace', $workplace))
            ->when($filters['pay_unit'] ?? null, fn ($query, $unit) => $query->where('pay_unit', $unit))
            ->when(isset($filters['minimum_pay']), fn ($query) => $query->where('pay_max', '>=', (int) round($filters['minimum_pay'] * 100)))
            ->when($site->isNetwork() && filled($filters['location'] ?? null), fn ($query) => $query->whereHas('branch.location',
                fn ($location) => $location->whereLike('name', '%'.$filters['location'].'%')))
            ->latest('published_at')->latest('id')->paginate(18)->withQueryString();

        return view('jobs.index', compact('items', 'filters'));
    }

    public function show(Request $request, int $job)
    {
        $item = $this->visible($request)->findOrFail($job);
        $canonical = NetworkUrl::to($this->jobs->canonicalSite($item), '/jobs/'.$item->id);
        if (parse_url($canonical, PHP_URL_HOST) !== $request->getHost()) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }
        $saved = $request->user() && DB::table('job_posting_bookmarks')
            ->where(['user_id' => $request->user()->id, 'job_posting_id' => $job])->exists();

        return view('jobs.show', compact('item', 'saved', 'canonical'));
    }

    public function business(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $branches = $employer->locations()->with('location')->when($site->location_id, fn ($query, $location) => $query->where('location_id', $location))->get();
        $statuses = ['pending', 'approved', 'rejected', 'expired', 'withdrawn'];
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'q' => 'nullable|string|max:100',
        ]);
        $owned = $this->jobs->scope($site->location_id)->whereIn('business_location_id', $branches->pluck('id'));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'description']))
            ->latest('id')->paginate(12)->withQueryString();

        return view('jobs.business', compact('employer', 'branches', 'items', 'statuses', 'filters', 'counts'));
    }

    public function store(Request $request, int $business)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $data = $this->postingData($request);
        $branch = $employer->locations()->findOrFail($data['business_location_id']);
        LocationScope::forPublication($site, $branch->location_id);
        JobPosting::create($data + ['author_id' => $request->user()->id]);

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job submitted for review.');
    }

    public function edit(Request $request, int $business, int $job)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $item = $this->managedJob($request, $employer->id, $job);
        abort_unless(in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);

        return view('jobs.edit', compact('employer', 'item'));
    }

    public function update(Request $request, int $business, int $job)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->jobs->enabled($site), 404);
        $employer = $this->managedBusiness($request, $business);
        $data = $this->postingData($request, needsBranch: false);
        DB::transaction(function () use ($request, $employer, $job, $data) {
            $item = $this->managedJob($request, $employer->id, $job, lock: true);
            abort_unless(in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
            $item->fill($data)->forceFill(['status' => 'pending'])->save();
        });

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job changes submitted for review.');
    }

    public function withdraw(Request $request, int $business, int $job)
    {
        $employer = $this->managedBusiness($request, $business);
        DB::transaction(function () use ($request, $employer, $job) {
            $item = $this->managedJob($request, $employer->id, $job, lock: true);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
            $item->forceFill(['status' => 'withdrawn'])->save();
        });

        return redirect('/account/businesses/'.$business.'/jobs')->with('status', 'Job withdrawn.');
    }

    public function save(Request $request, int $job)
    {
        $item = $this->visible($request)->findOrFail($job);
        DB::table('job_posting_bookmarks')->insertOrIgnore(['job_posting_id' => $item->id, 'user_id' => $request->user()->id]);

        return redirect('/account/saved-jobs')->with('status', 'Job saved.');
    }

    public function remove(Request $request, int $job)
    {
        DB::table('job_posting_bookmarks')->where(['job_posting_id' => $job, 'user_id' => $request->user()->id])->delete();

        return redirect('/account/saved-jobs')->with('status', 'Job removed from your saved list.');
    }

    public function saved(Request $request)
    {
        $items = $this->visible($request)->whereIn('id', DB::table('job_posting_bookmarks')
            ->where('user_id', $request->user()->id)->select('job_posting_id'))->latest('published_at')->paginate(18);

        return view('jobs.saved', compact('items'));
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
            'apply_url' => 'required|url:https|max:1024', 'closing_on' => 'required|date_format:Y-m-d|after_or_equal:'.$today,
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
