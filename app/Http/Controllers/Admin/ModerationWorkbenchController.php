<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\JobBoard;
use App\Support\Marketplace;
use App\Support\ModerationDecision;
use App\Support\ModerationQueue;
use App\Support\ModerationReports;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ModerationWorkbenchController extends Controller
{
    private const MAX_BATCH = 25;

    public function index(Request $request, ModerationQueue $queue, ModerationReports $reports)
    {
        $filters = array_filter($request->validate([
            'type' => ['nullable', Rule::in(array_keys(ModerationQueue::TYPES))],
            'status' => 'nullable|in:pending,reported,reviewed,all',
            'priority' => 'nullable|in:oldest,newest,reported',
            'location' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'q' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
        ]), fn ($value) => $value !== null);
        $filters += ['status' => 'pending', 'priority' => 'oldest'];
        $query = $queue->items($filters);
        if ($filters['priority'] === 'reported') {
            $query->orderByDesc('reported');
        }
        $direction = $filters['priority'] === 'newest' ? 'desc' : 'asc';
        $items = $query->orderBy('created_at', $direction)
            ->orderBy('type')->orderBy('id', $direction)->paginate(20)->appends($filters);
        $listings = Listing::with('media')->whereIn('id', $items->where('type', 'listing')->pluck('id'))->get()->keyBy('id');

        return view('admin.moderation-workbench.index', [
            'items' => $items, 'filters' => $filters, 'types' => ModerationQueue::TYPES,
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'counts' => $queue->counts($filters), 'listings' => $listings,
            'openReports' => $reports->queue()->where('status', 'open')->count(),
        ]);
    }

    public function show(string $type, int $item, ModerationQueue $queue)
    {
        $resource = ModerationQueue::find($type, $item);
        abort_unless($resource, 404);
        $row = $queue->items(['type' => $type])->where('id', $item)->firstOrFail();
        $ownerNames = $type === 'business' ? $resource->members()->wherePivot('role', 'owner')->pluck('users.name') : collect();
        $branches = match ($type) {
            'business' => $resource->locations,
            'claim' => DB::table('business_locations as branch')->join('locations', 'locations.id', '=', 'branch.location_id')
                ->where('branch.business_id', $resource->business_id)->get(['locations.name', 'branch.address']),
            default => collect(),
        };

        return view('admin.moderation-workbench.show', [
            'row' => $row, 'resource' => $resource, 'type' => $type, 'label' => ModerationQueue::TYPES[$type],
            'version' => ModerationDecision::version($type, $resource), 'ownerNames' => $ownerNames, 'branches' => $branches,
            'claimAccount' => $type === 'claim' ? User::whereKey($resource->user_id)->value('email') : null,
            'history' => $queue->history($type, $item)->paginate(15, pageName: 'history'),
        ]);
    }

    public function decide(Request $request, string $type, int $item)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in($this->actions($type))],
            'version' => ModerationDecision::VERSION_RULES,
            'reason' => 'required_if:action,reject,remove|nullable|string|max:300',
            'notes' => 'nullable|string|max:1200',
        ]);
        $this->apply($request, $type, $item, $data);

        return redirect()->route('moderation.items.show', ['type' => $type, 'item' => $item])
            ->with('status', 'Moderation decision saved.');
    }

    public function preview(Request $request, ModerationQueue $queue)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in($this->batchTypes())],
            'ids' => 'required|array|min:1|max:'.self::MAX_BATCH,
            'ids.*' => 'required|integer|min:1|distinct',
        ]);
        $type = $data['type'];
        $rows = $queue->items(['type' => $type, 'status' => 'pending'])->whereIn('id', $data['ids'])->get()->keyBy('id');
        $items = [];
        foreach ($data['ids'] as $id) {
            $resource = isset($rows[$id]) ? ModerationQueue::find($type, $id) : null;
            $version = $resource !== null && $resource->status === 'pending' && ! ($resource instanceof Listing && $resource->media->isNotEmpty())
                ? ModerationDecision::version($type, $resource) : null;
            $items[] = [
                'id' => $id, 'title' => $rows[$id]->title ?? null, 'version' => $version,
                'proof' => $version === null ? null : $this->previewProof($request->user()->id, $type, $id, $version),
            ];
        }

        return view('admin.moderation-workbench.bulk-preview', compact('type', 'items'));
    }

    public function bulk(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in($this->batchTypes())],
            'action' => 'required|in:approve,reject',
            'items' => 'required|array|min:1|max:'.self::MAX_BATCH,
            'reason' => 'required_if:action,reject|nullable|string|max:300',
            'notes' => 'nullable|string|max:1200',
        ]);
        $results = [];
        $seen = [];
        foreach ($data['items'] as $row) {
            $fields = is_array($row) ? $row : [];
            $validator = Validator::make($fields, [
                'id' => 'required|integer|min:1', 'version' => ModerationDecision::VERSION_RULES,
                'proof' => ModerationDecision::VERSION_RULES,
            ]);
            if ($validator->fails()) {
                $results[] = ['id' => is_numeric($fields['id'] ?? null) ? (int) $fields['id'] : null,
                    'success' => false, 'message' => $validator->errors()->has('id') ? 'Invalid submission ID' : 'Invalid review version'];

                continue;
            }
            $id = (int) $fields['id'];
            $results[] = match (true) {
                isset($seen[$id]) => ['id' => $id, 'success' => false, 'message' => 'Duplicate submission ID'],
                ! hash_equals($this->previewProof($request->user()->id, $data['type'], $id, $fields['version']), $fields['proof']) => ['id' => $id, 'success' => false, 'message' => 'Preview this submission again'],
                default => $this->applyBatchItem($request, $data, $id, $fields['version']),
            };
            $seen[$id] = true;
        }

        return view('admin.moderation-workbench.bulk-results', ['results' => $results]);
    }

    private function previewProof(int $actor, string $type, int $id, string $version): string
    {
        return hash_hmac('sha256', "moderation-preview:$actor:$type:$id:$version", app('encrypter')->getKey());
    }

    private function applyBatchItem(Request $request, array $data, int $id, string $version): array
    {
        try {
            $this->apply($request, $data['type'], $id, $data + ['version' => $version], bulk: true);

            return ['id' => $id, 'success' => true, 'message' => 'Decision saved'];
        } catch (ModelNotFoundException) {
            $message = 'Submission no longer available';
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();
        } catch (HttpExceptionInterface $exception) {
            $message = match ($exception->getStatusCode()) {
                409 => 'Changed or no longer eligible',
                403, 404 => 'Submission no longer available',
                default => throw $exception,
            };
        }

        return ['id' => $id, 'success' => false, 'message' => $message];
    }

    private function actions(string $type): array
    {
        return match ($type) {
            'listing' => ['approve', 'reject', 'remove'],
            'thread' => ['approve', 'reject', 'pin', 'unpin', 'lock', 'unlock'],
            'business', 'claim', 'business-update', 'job', 'reply', 'event' => ['approve', 'reject'],
            default => abort(404),
        };
    }

    private function batchTypes(): array
    {
        // Claims require individual verification of the ownership evidence.
        return array_diff(array_keys(ModerationQueue::TYPES), ['claim']);
    }

    private function apply(Request $request, string $type, int $id, array $data, bool $bulk = false): void
    {
        $fields = array_intersect_key($data, array_flip(['action', 'version', 'notes', 'reason']));
        $fields['decision'] = $data['action'] === 'approve' ? 'approved' : 'rejected';
        $fields['status'] = match ($data['action']) {
            'approve' => 'active',
            'reject' => 'rejected',
            'remove' => 'removed',
            default => null,
        };
        $decision = $request->duplicate([], $fields);
        $decision->setJson(new InputBag($fields));
        $decision->attributes->set('bulk_moderation', $bulk);
        match ($type) {
            'listing' => app(NetworkController::class)->moderate($decision, $id, app(Marketplace::class)),
            'business' => app(BusinessReviewController::class)->profile($decision, $id),
            'claim' => app(BusinessReviewController::class)->claim($decision, $id),
            'business-update' => app(BusinessReviewController::class)->update($decision, $id),
            'job' => app(JobReviewController::class)->update($decision, $id, app(JobBoard::class)),
            'thread' => app(CommunityModerationController::class)->threads($decision, $id),
            'reply' => app(CommunityModerationController::class)->replies($decision, $id),
            'event' => app(CommunityModerationController::class)->events($decision, $id),
        };
    }
}
