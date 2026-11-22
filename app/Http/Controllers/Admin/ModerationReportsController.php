<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Support\Audit;
use App\Support\ModerationReports;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ModerationReportsController extends Controller
{
    private const MAX_BATCH = 25;

    public function __construct(private readonly ModerationReports $reports) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(ModerationReports::TYPES))],
            'status' => ['nullable', Rule::in(['all', ...array_keys(ModerationReports::STATUSES)])],
            'priority' => 'nullable|in:newest,oldest',
            'location' => 'nullable|integer|min:1',
            'source' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'until' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'page' => 'nullable|integer|min:1',
        ]);
        $filters['status'] ??= 'open';
        $filters['priority'] ??= 'oldest';
        $order = $filters['priority'] === 'oldest' ? 'asc' : 'desc';
        $queue = $this->reports->queue()
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['location'] ?? null, fn ($query, $location) => $query->where('location_id', (int) $location))
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source_id', (int) $source))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->whereLike('title', '%'.$term.'%'))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('submitted_at', '>=', $from))
            ->when($filters['until'] ?? null, fn ($query, $until) => $query->whereDate('submitted_at', '<=', $until))
            ->orderBy('submitted_at', $order)->orderBy('type')->orderBy('id', $order)
            ->paginate(self::MAX_BATCH)->withQueryString();
        $summary = $this->reports->queue()->selectRaw('type, status, COUNT(*) AS total')->groupBy('type', 'status')->get();
        $locations = Location::orderBy('name')->pluck('name', 'id');

        return view('admin.moderation-reports.index', compact('queue', 'summary', 'filters', 'locations'));
    }

    public function show(Request $request, string $type, int $report): View
    {
        $record = $this->reports->queue()->where(['type' => $type, 'id' => $report])->firstOrFail();
        $source = $this->reports->source($type, $report);
        $version = ModerationReports::version($type, $source);
        $location = Location::find($record->location_id)?->name;
        $decision = $this->reports->decision($type, $report);
        if ($decision === null && $type === 'listing' && isset($source->resolved_at)) {
            $decision = $this->reports->legacyDecision($source);
        }
        $reason = null;
        if ($type !== 'conversation') {
            Audit::record('moderation.report-viewed', $request->user()->id, context: ['type' => $type, 'report' => $report]);
            $reason = $source->reason;
        }

        return view('admin.moderation-reports.show', compact('record', 'location', 'version', 'decision', 'reason'));
    }

    public function decide(Request $request, string $type, int $report): RedirectResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:resolved,dismissed', 'note' => 'required|string|max:240',
            'version' => ModerationReports::VERSION_RULE,
        ]);
        $this->reports->decide($request->user()->id, $type, $report, $data['version'], $data['decision'], $data['note']);

        return redirect()->route('moderation.reports.show', ['type' => $type, 'report' => $report])->with('status', 'Report decision saved.');
    }

    public function evidence(Request $request, int $report): Response
    {
        [$reason, $message] = $this->reports->evidence($request->user()->id, $report);

        return response()->view('admin.moderation-reports.evidence', compact('reason', 'message', 'report'), headers: [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function preview(Request $request): View
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ModerationReports::TYPES))],
            'items' => 'required|array|min:1|max:'.self::MAX_BATCH,
            'items.*' => 'required|integer|min:1|distinct',
        ]);
        $type = $data['type'];
        $records = $this->reports->queue()->where('type', $type)->whereIn('id', $data['items'])->where('status', 'open')->get()->keyBy('id');
        $preview = [];
        foreach ($data['items'] as $id) {
            $record = $records->get($id);
            try {
                $version = $record === null ? null : ModerationReports::version($type, $this->reports->source($type, $id));
            } catch (RecordNotFoundException) {
                $version = null;
            }
            $preview[] = [
                'id' => $id, 'title' => $record?->title, 'version' => $version,
                'proof' => $version === null ? null : $this->previewProof($request->user()->id, $type, $id, $version),
            ];
        }

        return view('admin.moderation-reports.bulk-preview', compact('type', 'preview'));
    }

    public function bulkResolve(Request $request): View
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ModerationReports::TYPES))],
            'note' => 'required|string|max:240',
            'items' => 'required|array|min:1|max:'.self::MAX_BATCH,
        ]);
        $type = $data['type'];
        $actor = $request->user()->id;
        $seen = [];
        $results = [];
        foreach (array_values($data['items']) as $position => $item) {
            $validator = Validator::make(['item' => $item], [
                'item' => 'required|array:id,version,proof',
                'item.id' => 'required|integer|min:1',
                'item.version' => ModerationReports::VERSION_RULE,
                'item.proof' => ModerationReports::VERSION_RULE,
            ]);
            if ($validator->fails()) {
                $results[] = ['item' => 'Selection '.($position + 1), 'result' => 'Invalid report ID or version.', 'success' => false];

                continue;
            }
            $id = (int) $item['id'];
            $result = match (true) {
                isset($seen[$id]) => ['Duplicate selection.', false],
                ! hash_equals($this->previewProof($actor, $type, $id, $item['version']), $item['proof']) => ['Preview this report again before resolving it.', false],
                default => $this->resolveOne($actor, $type, $id, $item['version'], $data['note']),
            };
            $seen[$id] = true;
            $results[] = ['item' => '#'.$id, 'result' => $result[0], 'success' => $result[1]];
        }

        return view('admin.moderation-reports.bulk-results', compact('type', 'results'));
    }

    private function previewProof(int $actor, string $type, int $id, string $version): string
    {
        return hash_hmac('sha256', "moderation-report-preview:$actor:$type:$id:$version", app('encrypter')->getKey());
    }

    private function resolveOne(int $actor, string $type, int $id, string $version, string $note): array
    {
        try {
            $this->reports->decide($actor, $type, $id, $version, 'resolved', $note);

            return ['Resolved.', true];
        } catch (RecordNotFoundException) {
            return ['Report unavailable.', false];
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [409, 503], true)) {
                return [$exception->getMessage(), false];
            }

            throw $exception;
        }
    }
}
