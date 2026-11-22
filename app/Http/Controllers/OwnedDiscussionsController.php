<?php

namespace App\Http\Controllers;

use App\Models\CommunityThread;
use App\Support\ApprovalPolicy;
use App\Support\Audit;
use App\Support\Community;
use App\Support\DiscussionReportEvidence;
use App\Support\ModerationDecision;
use App\Support\NotificationInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OwnedDiscussionsController extends Controller
{
    private const EDITABLE = ['pending', 'approved', 'rejected'];

    private const WITHDRAWABLE = ['pending', 'approved'];

    public function __construct(private readonly Community $community) {}

    public function index(Request $request): View
    {
        $items = $this->owned($request)->select('id', 'title', 'location_id', 'status', 'locked', 'created_at')
            ->with('location:id,name')
            ->withCount(['replies' => fn (Builder $query) => $query->where('status', 'approved')])
            ->latest('created_at')->latest('id')->paginate(18);
        $reasons = $this->rejectionReasons($items->getCollection()->where('status', 'rejected')->modelKeys());

        return view('community.own-discussions', compact('items', 'reasons'));
    }

    public function preview(Request $request, int $thread): View
    {
        $item = $this->owned($request)->with('location:id,name')->findOrFail($thread);
        $reason = $item->status === 'rejected' ? ($this->rejectionReasons([$item->id])[$item->id] ?? null) : null;
        $canEdit = $this->editable($item);
        $canWithdraw = ! $item->locked && in_array($item->status, self::WITHDRAWABLE, true);
        $canRestore = ! $item->locked && $item->status === 'withdrawn';
        $version = $canWithdraw || $canRestore ? ModerationDecision::version('thread', $item) : null;

        return view('community.preview-discussion', compact('item', 'reason', 'canEdit', 'canWithdraw', 'canRestore', 'version'));
    }

    public function edit(Request $request, int $thread): View
    {
        $item = $this->owned($request)->with('location:id,name')->findOrFail($thread);
        abort_unless($this->editable($item), 409, 'This discussion cannot be edited in its current state.');
        $version = ModerationDecision::version('thread', $item);
        $draft = $item->only('title', 'body', 'category');
        $submittedVersion = $request->old('version');
        if (is_string($submittedVersion) && hash_equals($version, $submittedVersion)) {
            foreach (array_keys($draft) as $field) {
                $submitted = $request->old($field);
                if (is_string($submitted)) {
                    $draft[$field] = $submitted;
                }
            }
        }

        return view('community.edit-discussion', compact('item', 'version', 'draft'));
    }

    public function update(Request $request, int $thread, ApprovalPolicy $policy): RedirectResponse
    {
        $message = DB::transaction(function () use ($request, $thread, $policy) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($thread);
            $data = $request->validate([
                'version' => ModerationDecision::VERSION_RULES,
                'title' => 'required|string|max:140',
                'body' => 'required|string|min:10|max:5000',
                'category' => ['required', Rule::in(array_keys(Community::FORUMS))],
                'location_id' => 'sometimes|nullable|integer',
            ]);
            ModerationDecision::requireCurrent('thread', $item, $data['version']);
            abort_unless($this->editable($item), 409, 'This discussion cannot be edited in its current state.');
            if (array_key_exists('location_id', $data)) {
                $location = $data['location_id'] === null ? null : (int) $data['location_id'];
                $originalLocation = $item->location_id === null ? null : (int) $item->location_id;
                if ($location !== $originalLocation) {
                    throw ValidationException::withMessages(['location_id' => 'The original discussion location is fixed.']);
                }
            }

            $body = str_replace(["\r\n", "\r"], "\n", $data['body']);
            if ($data['title'] === $item->title && $data['category'] === $item->category
                && $body === str_replace(["\r\n", "\r"], "\n", $item->body)) {
                return 'No changes were made.';
            }

            $previous = $item->status;
            $site = $request->attributes->get('site');
            // Existing drafts stay in review, and a moderator's rejection requires a new manual decision.
            $revision = $previous === 'approved' ? $policy->automaticRevision($site, 'thread', $item->location_id) : null;
            DiscussionReportEvidence::preserve($item, $item->author_id);
            $item->fill(['title' => $data['title'], 'body' => $body, 'category' => $data['category']]);
            $item->forceFill($revision === null ? ['status' => 'pending'] : ['status' => 'approved', 'last_activity_at' => now()])->save();
            $approvalNotice = $revision === null ? null
                : $policy->recordAutomaticApproval('thread', $thread, $item->author_id, $site, $revision, $item->location_id);
            $this->recordChange($request, $item, 'author-edit', $previous, $approvalNotice);

            return $revision === null ? 'Your changes were submitted for review.' : 'Your changes were published.';
        }, attempts: 3);

        return $this->backToDiscussion($thread, $message);
    }

    public function withdraw(Request $request, int $thread): RedirectResponse
    {
        $this->changeStatus($request, $thread, 'author-withdraw', self::WITHDRAWABLE, 'withdrawn');

        return $this->backToDiscussion($thread, 'Discussion withdrawn.');
    }

    public function restore(Request $request, int $thread): RedirectResponse
    {
        $this->changeStatus($request, $thread, 'author-restore', ['withdrawn'], 'pending');

        return $this->backToDiscussion($thread, 'Discussion restored and submitted for review.');
    }

    private function owned(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return CommunityThread::where('author_id', $request->user()->id)
            ->when($site->location_id !== null, fn (Builder $query) => $query->where('location_id', $site->location_id));
    }

    private function editable(CommunityThread $item): bool
    {
        return ! $item->locked && in_array($item->status, self::EDITABLE, true);
    }

    private function changeStatus(Request $request, int $thread, string $action, array $allowed, string $status): void
    {
        DB::transaction(function () use ($request, $thread, $action, $allowed, $status) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($thread);
            $data = $request->validate(['version' => ModerationDecision::VERSION_RULES]);
            ModerationDecision::requireCurrent('thread', $item, $data['version']);
            abort_unless(! $item->locked && in_array($item->status, $allowed, true), 409, 'This discussion cannot be changed in its current state.');
            $previous = $item->status;
            $item->forceFill(['status' => $status])->save();
            $this->recordChange($request, $item, $action, $previous);
        }, attempts: 3);
    }

    private function recordChange(Request $request, CommunityThread $item, string $action, string $previous, ?int $approvalNotice = null): void
    {
        $site = $request->attributes->get('site');
        $audit = Audit::record('community.thread.'.$action, $item->author_id, $site->id, [
            'thread' => $item->id, 'previous_status' => $previous, 'status' => $item->status,
        ]);
        $statusChanged = $previous !== $item->status;
        ModerationDecision::record('thread', $item->id, $action, $audit, [], $statusChanged ? $audit : $approvalNotice);
        if ($statusChanged) {
            NotificationInbox::record($item->author_id, 'thread.'.$item->status, $audit, $item->id, [$item->location_id]);
        }
    }

    private function rejectionReasons(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $decision = DB::table('moderation_decisions')->select('reason')->where('type', 'thread')->where('action', 'reject')
            ->whereColumn('resource_id', 'community_threads.id')->latest('id')->limit(1);
        // Earlier passes stored the member-facing reason in the audit context.
        $legacy = DB::table('audit_logs')->select('context->reason')->where('action', 'community.thread.reject')
            ->whereColumn('context->thread', 'community_threads.id')->whereNotNull('context->reason')->latest('id')->limit(1);

        return CommunityThread::whereKey($ids)->where('status', 'rejected')->select('id')
            ->selectRaw('COALESCE(('.$decision->toSql().'), ('.$legacy->toSql().')) as reason', [...$decision->getBindings(), ...$legacy->getBindings()])
            ->pluck('reason', 'id')->all();
    }

    private function backToDiscussion(int $thread, string $message): RedirectResponse
    {
        return redirect("/account/discussions/$thread/preview")->with('status', $message);
    }
}
