<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Support\Audit;
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
        ]);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = CommunityThread::lockForUpdate()->findOrFail($thread);
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
                default => $item->status === 'approved',
            }, 409);
            $item->forceFill($changes)->save();
            Audit::record('community.thread.'.$action, $request->user()->id, context: ['thread' => $thread, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    public function replies(Request $request, int $reply)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'reason' => 'required_if:action,reject|nullable|string|max:300']);
        DB::transaction(function () use ($request, $reply, $data) {
            $initial = CommunityReply::findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $thread->replies()->lockForUpdate()->findOrFail($reply);
            $approval = $data['action'] === 'approve';
            abort_unless($approval ? $item->status === 'pending' : in_array($item->status, ['pending', 'approved'], true), 409);
            if ($approval) {
                abort_unless($thread->status === 'approved', 409);
                $thread->forceFill(['last_activity_at' => now()])->save();
                DB::table('community_notifications')->insertUsing(['user_id', 'reply_id', 'created_at'],
                    DB::table('community_thread_follows')->where('thread_id', $thread->id)
                        ->when($item->author_id, fn ($query, $author) => $query->where('user_id', '!=', $author))
                        ->select('user_id')->selectRaw('?, ?', [$item->id, now()]),
                );
            }
            $item->forceFill(['status' => $approval ? 'approved' : 'rejected'])->save();
            Audit::record('community.reply.'.$data['action'], $request->user()->id, context: ['reply' => $reply, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    public function events(Request $request, int $event)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'reason' => 'required_if:action,reject|nullable|string|max:300']);
        DB::transaction(function () use ($request, $event, $data) {
            $item = CommunityEvent::lockForUpdate()->findOrFail($event);
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending', 409);
                abort_if(($item->localEnd() ?? $item->localStart())->isPast(), 409, 'This event has already ended.');
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved', 'cancelled'], true), 409);
            }
            $item->forceFill(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected'])->save();
            Audit::record('community.event.'.$data['action'], $request->user()->id, context: ['event' => $event, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    private function done()
    {
        return redirect('/admin/community')->with('status', 'Moderation decision saved.');
    }
}
