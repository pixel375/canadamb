<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Support\Audit;
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
            'events' => CommunityEvent::whereIn('status', ['pending', 'approved', 'cancelled'])->with('location')
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
        $data = $this->decision($request);
        DB::transaction(function () use ($request, $event, $data) {
            $item = CommunityEvent::lockForUpdate()->findOrFail($event);
            ModerationDecision::requireCurrent('event', $item, $data['version']);
            abort_if($request->attributes->get('bulk_moderation') && $item->status !== 'pending', 409);
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending', 409);
                abort_if(($item->localEnd() ?? $item->localStart())->isPast(), 409, 'This event has already ended.');
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved', 'cancelled'], true), 409);
            }
            $item->forceFill(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected'])->save();
            $audit = Audit::record('community.event.'.$data['action'], $request->user()->id, context: ['event' => $event]);
            ModerationDecision::record('event', $event, $data['action'], $audit, $data, $audit);
            if ($item->author_id !== null) {
                NotificationInbox::record($item->author_id, 'event.'.$item->status, $audit, $event, [$item->location_id]);
            }
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
