<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\BroadcastConsent;
use App\Support\EventDiscussions;
use App\Support\NotificationPreferences;
use App\Support\OwnerNotifications;
use App\Support\PushDevices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    public function preview(Request $request)
    {
        abort_unless(BroadcastConsent::enabled(), 404);
        $data = $request->validate([
            'title' => 'required|string|max:120', 'body' => 'required|string|max:1000',
            'audience' => 'required|in:all,business-owners,selected',
            'member_ids' => 'required_if:audience,selected|nullable|string|max:600',
        ]);
        $members = [];
        if ($data['audience'] === 'selected') {
            $members = preg_split('/[\s,]+/', trim($data['member_ids']), -1, PREG_SPLIT_NO_EMPTY);
            if (count($members) > 50 || collect($members)->contains(fn ($id) => ! preg_match('/^[1-9][0-9]{0,17}$/D', $id))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['member_ids' => 'Enter up to 50 positive member reference numbers.']);
            }
        }
        $count = BroadcastConsent::audience($data['audience'], $members)->count();
        Audit::record('broadcast.previewed', $request->user()->id, context: ['audience' => $data['audience'], 'eligible_count' => $count]);

        return response()->view('admin.broadcast-preview', ['draft' => $data, 'count' => $count])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $days = (int) ($request->validate(['days' => 'nullable|in:7,30,90'])['days'] ?? 30);
        $since = now()->subDays($days);
        // Only aggregate counts; no recipient identities, private text or hidden records.
        $visible = DB::table('community_notifications as notifications')
            ->join('community_replies as replies', 'replies.id', '=', 'notifications.reply_id')
            ->join('community_threads as threads', 'threads.id', '=', 'replies.thread_id')
            ->where('notifications.created_at', '>=', $since)
            ->where('threads.status', 'approved')->where('replies.status', 'approved');
        $metrics = [
            'created' => (clone $visible)->count(),
            'unread' => (clone $visible)->whereNull('notifications.read_at')->count(),
            'recipients' => (clone $visible)->distinct()->count('notifications.user_id'),
        ];
        $preferencesEnabled = NotificationPreferences::enabled();
        $paused = $preferencesEnabled ? DB::table('notification_preferences')->where('discussion_replies', false)->count() : null;
        $eventsEnabled = EventDiscussions::enabled();
        $ownerEnabled = OwnerNotifications::enabled();
        $ownerMetrics = $ownerEnabled ? DB::table('owner_notifications')->where('created_at', '>=', $since)
            ->selectRaw('decision, COUNT(*) as total')->groupBy('decision')->pluck('total', 'decision') : collect();

        $broadcastEnabled = BroadcastConsent::enabled();
        $pushEnabled = PushDevices::enabled();
        $pushKeyConfigured = PushDevices::publicKey() !== null;
        $pushCount = $pushEnabled ? DB::table('push_devices')->count() : null;

        return response()->view('admin.notifications', compact('days', 'metrics', 'preferencesEnabled', 'paused', 'eventsEnabled', 'ownerEnabled', 'ownerMetrics', 'pushEnabled', 'pushKeyConfigured', 'pushCount', 'broadcastEnabled'))
            ->header('Cache-Control', 'private, no-store');
    }
}
