<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\Audit;
use App\Support\KeywordSearch;
use App\Support\OwnerNotifications;
use App\Support\ReplyPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunityModerationController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,cancelled,rejected,withdrawn', 'q' => 'nullable|string|max:100',
            'location' => 'nullable|integer|exists:locations,id']);
        $location = $filters['location'] ?? null;
        $threads = CommunityThread::whereIn('status', ['pending', 'approved', 'rejected', 'withdrawn'])
            ->when($location, fn ($query, $id) => $query->where('location_id', $id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']));
        $replies = CommunityReply::whereIn('status', ['pending', 'approved', 'rejected', 'withdrawn'])
            ->where(fn ($query) => $query->whereIn('status', ['rejected', 'withdrawn'])
                ->orWhereHas('thread', fn ($thread) => $thread->where('status', 'approved')))
            ->when($location, fn ($query, $id) => $query->whereHas('thread', fn ($thread) => $thread->where('location_id', $id)))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['body']));
        $events = CommunityEvent::whereIn('status', ['pending', 'approved', 'cancelled', 'rejected', 'withdrawn'])
            ->when($location, fn ($query, $id) => $query->where('location_id', $id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body', 'venue']));
        $counts = collect();
        foreach ([$threads, $replies, $events] as $query) {
            foreach ((clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $count) {
                $counts[$status] = ($counts[$status] ?? 0) + $count;
            }
        }
        $status = $filters['status'] ?? null;

        return view('admin.community', [
            'filters' => $filters, 'counts' => $counts, 'places' => Location::selectable()->orderBy('name')->get(['id', 'name']),
            'threads' => $threads->when($status, fn ($query) => $query->where('status', $status))->with(['location', 'author'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")->latest('id')->paginate(15, pageName: 'threads')->withQueryString(),
            'replies' => $replies->when($status, fn ($query) => $query->where('status', $status))->with(['thread', 'author'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")->latest('id')->paginate(15, pageName: 'replies')->withQueryString(),
            'events' => $events->when($status, fn ($query) => $query->where('status', $status))->with('location')
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")->latest('id')->paginate(15, pageName: 'events')->withQueryString(),
        ]);
    }

    public function threads(Request $request, int $thread)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,pin,unpin,lock,unlock',
            'version' => 'nullable|string|size:64',
            'reason' => 'required_if:action,reject|nullable|string|max:300',
        ]);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = CommunityThread::lockForUpdate()->findOrFail($thread);
            if (isset($data['version']) && ! hash_equals($item->contentVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The discussion changed. Reload the moderation queue before reviewing it.']);
            }
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
            if (in_array($action, ['approve', 'reject'], true)) {
                OwnerNotifications::record($item->author_id, 'discussion', $item->id, $action === 'approve' ? 'approved' : 'rejected');
            }
            Audit::record('community.thread.'.$action, $request->user()->id, context: ['thread' => $thread, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    public function replies(Request $request, int $reply)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'version' => 'required|string|size:64', 'reason' => 'required_if:action,reject|nullable|string|max:300']);
        DB::transaction(function () use ($request, $reply, $data) {
            $initial = CommunityReply::findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $thread->replies()->lockForUpdate()->findOrFail($reply);
            if (! hash_equals($item->contentVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The reply changed. Reload the moderation queue before reviewing it.']);
            }
            $approval = $data['action'] === 'approve';
            abort_unless($approval ? $item->status === 'pending' : in_array($item->status, ['pending', 'approved'], true), 409);
            if ($approval) {
                abort_unless($thread->status === 'approved', 409);
                ReplyPublication::approve($thread, $item);
            }
            if (! $approval) {
                $item->forceFill(['status' => 'rejected'])->save();
            }
            OwnerNotifications::record($item->author_id, 'reply', $item->id, $approval ? 'approved' : 'rejected');
            Audit::record('community.reply.'.$data['action'], $request->user()->id, context: ['reply' => $reply, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    public function events(Request $request, int $event)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'version' => 'nullable|string|size:64', 'reason' => 'required_if:action,reject|nullable|string|max:300']);
        DB::transaction(function () use ($request, $event, $data) {
            $item = CommunityEvent::lockForUpdate()->findOrFail($event);
            if (isset($data['version']) && ! hash_equals($item->contentVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The event changed. Reload the moderation queue before reviewing it.']);
            }
            if ($data['action'] === 'approve') {
                abort_unless($item->status === 'pending', 409);
                abort_if(($item->localEnd() ?? $item->localStart())->isPast(), 409, 'This event has already ended.');
            } else {
                abort_unless(in_array($item->status, ['pending', 'approved', 'cancelled'], true), 409);
            }
            $item->forceFill(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected'])->save();
            OwnerNotifications::record($item->author_id, 'event', $item->id, $data['action'] === 'approve' ? 'approved' : 'rejected');
            Audit::record('community.event.'.$data['action'], $request->user()->id, context: ['event' => $event, 'reason' => $data['reason'] ?? null]);
        });

        return $this->done();
    }

    private function done()
    {
        return redirect('/admin/community')->with('status', 'Moderation decision saved.');
    }
}
