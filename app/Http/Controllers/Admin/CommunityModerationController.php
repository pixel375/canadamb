<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Support\Audit;
use App\Support\Community;
use App\Support\EventMetadata;
use App\Support\LinkedEvents;
use App\Support\ModerationDecision;
use App\Support\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityModerationController extends Controller
{
    public function index()
    {
        return view('admin.community', [
            'threads' => CommunityThread::whereIn('status', ['pending', 'approved'])->with(['location', 'author'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(15, pageName: 'threads'),
            'replies' => CommunityReply::whereIn('status', ['pending', 'approved'])->whereHas('thread', fn ($query) => $query->where('status', 'approved'))
                ->with(['thread', 'author'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(15, pageName: 'replies'),
            'events' => CommunityEvent::whereIn('status', ['pending', 'approved', 'cancelled'])->with(['location', 'thread'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(15, pageName: 'events'),
        ]);
    }

    public function threads(Request $request, int $thread)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,pin,unpin,lock,unlock',
            'reason' => 'required_if:action,reject|nullable|string|max:300',
            'version' => ModerationDecision::VERSION_RULES,
            'notes' => 'nullable|string|max:1200',
        ]);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = CommunityThread::lockForUpdate()->findOrFail($thread);
            ModerationDecision::requireCurrent('thread', $item, $data['version']);
            abort_if($request->attributes->get('bulk_moderation') && $item->status !== 'pending', 409);
            $action = $data['action'];
            $changes = match ($action) {
                'approve' => ['status' => 'approved', 'last_activity_at' => now()],
                'reject' => ['status' => 'rejected'],
                'pin', 'unpin' => ['pinned' => $action === 'pin'],
                'lock', 'unlock' => ['locked' => $action === 'lock'],
            };
            abort_unless(match ($action) {
                'approve' => $item->status === 'pending',
                'reject' => in_array($item->status, ['pending', 'approved'], true),
                'pin', 'unpin' => $item->status === 'approved' && $item->pinned !== $changes['pinned'],
                'lock', 'unlock' => $item->status === 'approved' && $item->locked !== $changes['locked'],
            }, 409);
            $item->forceFill($changes)->save();
            if (in_array($action, ['reject', 'lock'], true)) {
                LinkedEvents::requeue($item, $request->user()->id);
            }
            $audit = Audit::record('community.thread.'.$action, $request->user()->id, context: ['thread' => $thread]);
            ModerationDecision::record('thread', $thread, $action, $audit, $data, isset($changes['status']) ? $audit : null);
            if ($item->author_id !== null && isset($changes['status'])) {
                NotificationInbox::record($item->author_id, 'thread.'.$item->status, $audit, $thread, [$item->location_id]);
            }
        }, attempts: 3);

        return $this->done();
    }

    public function replies(Request $request, int $reply)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($request, $reply, $data) {
            $initial = CommunityReply::findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $thread->replies()->lockForUpdate()->findOrFail($reply);
            $item->setRelation('thread', $thread);
            ModerationDecision::requireCurrent('reply', $item, $data['version']);
            abort_if($request->attributes->get('bulk_moderation') && $item->status !== 'pending', 409);
            $approval = $data['action'] === 'approve';
            abort_unless($approval ? $item->status === 'pending' : in_array($item->status, ['pending', 'approved'], true), 409);
            if ($approval) {
                abort_unless($thread->status === 'approved', 409);
                $thread->forceFill(['last_activity_at' => now()])->save();
            }
            $item->forceFill(['status' => $approval ? 'approved' : 'rejected'])->save();
            $audit = Audit::record('community.reply.'.$data['action'], $request->user()->id, context: ['reply' => $reply]);
            ModerationDecision::record('reply', $reply, $data['action'], $audit, $data, $audit);
            $locations = [$thread->location_id];
            if ($item->author_id !== null) {
                NotificationInbox::record($item->author_id, 'reply.'.$item->status, $audit, $reply, $locations);
            }
            if ($approval) {
                NotificationInbox::recordFollowedReply($thread->id, $reply, $item->author_id, $thread->location_id);
            }
        }, attempts: 3);

        return $this->done();
    }

    public function events(Request $request, int $event)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,edit,cancel',
            'reason' => 'required_if:action,reject,cancel,edit|nullable|string|max:300',
            'version' => ModerationDecision::VERSION_RULES,
            'notes' => 'nullable|string|max:1200',
        ]);
        DB::transaction(function () use ($request, $event, $data) {
            $item = LinkedEvents::lock($event);
            $thread = $item->thread;
            abort_if($request->attributes->get('bulk_moderation') && $thread !== null, 409, 'Review linked events individually.');
            if ($thread !== null) {
                $source = $request->validate([
                    'thread_version' => ModerationDecision::VERSION_RULES,
                    'reason' => 'required|string|max:300',
                ]);
                ModerationDecision::requireCurrent('thread', $thread, $source['thread_version']);
            }
            ModerationDecision::requireCurrent('event', $item, $data['version']);
            abort_if($request->attributes->get('bulk_moderation') && $item->status !== 'pending', 409);
            $action = $data['action'];
            $before = $item->status;
            abort_unless(match ($action) {
                'approve' => $before === 'pending',
                'reject' => in_array($before, ['pending', 'approved', 'cancelled'], true),
                'edit' => in_array($before, ['pending', 'approved'], true),
                'cancel' => in_array($before, ['pending', 'approved'], true),
            }, 409);
            if (in_array($action, ['approve', 'edit'], true)) {
                abort_if($thread !== null && ! LinkedEvents::matches($item, $thread), 409, 'The source discussion is ineligible.');
            }
            if ($action === 'approve') {
                abort_if(($item->localEnd() ?? $item->localStart())->isPast(), 409, 'This event has already ended.');
                if ($thread !== null) {
                    $item->fill(['title' => $thread->title, 'body' => $thread->body]);
                }
            } elseif ($action === 'edit') {
                $item->fill(EventMetadata::validated($request, app(Community::class)));
                if (! $item->isDirty()) {
                    return;
                }
            } elseif ($action === 'cancel') {
                $item->forceFill(['cancelled_at' => now()]);
            }
            if (in_array($action, ['approve', 'edit'], true) && $item->isDirty()) {
                LinkedEvents::preserveReportedMetadata($item);
            }
            $item->forceFill(['status' => match ($action) {
                'approve' => 'approved',
                'reject' => 'rejected',
                'edit' => 'pending',
                'cancel' => $before === 'approved' ? 'cancelled' : 'withdrawn',
            }])->save();
            LinkedEvents::record($item, $action, $request->user()->id, $before, $data);
        }, attempts: 3);

        return $this->done();
    }

    private function done()
    {
        return redirect('/admin/community')->with('status', 'Moderation decision saved.');
    }

    private function decision(Request $request): array
    {
        return $request->validate([
            'action' => 'required|in:approve,reject', 'reason' => 'required_if:action,reject|nullable|string|max:300',
            'version' => ModerationDecision::VERSION_RULES, 'notes' => 'nullable|string|max:1200',
        ]);
    }
}
