<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\EventDiscussions;
use App\Support\NotificationPreferences;
use App\Support\OwnerNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
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

        return response()->view('admin.notifications', compact('days', 'metrics', 'preferencesEnabled', 'paused', 'eventsEnabled', 'ownerEnabled', 'ownerMetrics'))
            ->header('Cache-Control', 'private, no-store');
    }
}
