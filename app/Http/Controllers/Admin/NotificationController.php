<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Announcements;
use App\Support\Audit;
use App\Support\BroadcastConsent;
use App\Support\EventDiscussions;
use App\Support\NotificationPreferences;
use App\Support\OwnerNotifications;
use App\Support\PushDelivery;
use App\Support\PushDevices;
use App\Support\VapidPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                throw ValidationException::withMessages(['member_ids' => 'Enter up to 50 positive member reference numbers.']);
            }
        }
        $count = BroadcastConsent::audience($data['audience'], $members)->count();
        Audit::record('broadcast.previewed', $request->user()->id, context: ['audience' => $data['audience'], 'eligible_count' => $count]);

        $request->session()->forget('announcement_confirmation');
        $confirmation = null;
        if (Announcements::canSend() && $count > 0 && $count <= Announcements::MAX_RECIPIENTS) {
            $ids = Announcements::audienceIds($data['audience'], $members);
            if (count($ids) === $count) {
                $confirmation = bin2hex(random_bytes(32));
                $request->session()->put('announcement_confirmation', [
                    'token' => $confirmation, 'actor' => $request->user()->id, 'expires' => now()->addMinutes(10)->timestamp,
                    'title' => $data['title'], 'body' => $data['body'], 'audience' => $data['audience'], 'members' => $members, 'ids' => $ids,
                ]);
            }
        }

        return response()->view('admin.broadcast-preview', ['draft' => $data, 'count' => $count, 'confirmation' => $confirmation])
            ->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request)
    {
        abort_unless(Announcements::canSend(), 404);
        $data = $request->validate(['confirmation' => 'required|string|size:64', 'confirm_send' => 'accepted']);
        $draft = $request->session()->get('announcement_confirmation');
        abort_unless(is_array($draft) && $draft['actor'] === $request->user()->id
            && $draft['expires'] >= now()->timestamp && hash_equals($draft['token'], $data['confirmation']), 409, 'Preview this announcement again.');
        $hash = hash('sha256', $draft['token']);
        $count = DB::transaction(function () use ($request, $draft, $hash) {
            // The administrator row serializes duplicate confirmations in this account.
            User::lockForUpdate()->findOrFail($request->user()->id);
            $existing = DB::table('announcements')->where('confirmation_hash', $hash)->first();
            if ($existing) {
                return (int) $existing->recipient_count;
            }
            // Lock original recipients in stable order; consent changes lock the same user rows.
            User::whereIn('id', $draft['ids'])->orderBy('id')->lockForUpdate()->get(['id']);
            $current = BroadcastConsent::audience($draft['audience'], $draft['members'])->orderBy('users.id')
                ->limit(Announcements::MAX_RECIPIENTS + 1)->lockForUpdate()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            abort_unless($current === $draft['ids'] && count($current) > 0 && count($current) <= Announcements::MAX_RECIPIENTS, 409, 'Audience changed. Preview again.');
            $id = DB::table('announcements')->insertGetId([
                'author_id' => $request->user()->id, 'confirmation_hash' => $hash, 'title' => $draft['title'],
                'body' => $draft['body'], 'audience' => $draft['audience'], 'recipient_count' => count($current), 'created_at' => now(),
            ]);
            foreach (array_chunk($current, 100) as $chunk) {
                DB::table('announcement_receipts')->insert(array_map(fn ($member) => [
                    'announcement_id' => $id, 'user_id' => $member, 'created_at' => now(),
                ], $chunk));
            }
            Audit::record('broadcast.delivered-in-site', $request->user()->id, context: ['announcement' => $id, 'recipient_count' => count($current)]);

            return count($current);
        }, attempts: 3);
        $request->session()->forget('announcement_confirmation');

        return redirect('/admin/notifications')->with('status', 'Announcement added to '.$count.' member notification centres.');
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
        $announcementDelivery = Announcements::canSend();
        $announcementHistory = Announcements::enabled() ? DB::table('announcements')->where('created_at', '>=', $since)
            ->orderByDesc('id')->limit(20)->get(['id', 'title', 'audience', 'recipient_count', 'created_at']) : collect();
        $announcementMetrics = Announcements::enabled() ? DB::table('announcement_receipts')
            ->join('announcements', 'announcements.id', '=', 'announcement_receipts.announcement_id')
            ->where('announcements.created_at', '>=', $since)
            ->selectRaw('COUNT(*) AS retained, COUNT(announcement_receipts.read_at) AS read_total')->first() : null;
        $pushEnabled = PushDevices::enabled();
        $pushKeyConfigured = PushDevices::publicKey() !== null;
        $pushCount = $pushEnabled ? DB::table('push_devices')->count() : null;
        $pushDeliveryEnabled = PushDelivery::enabled();
        $pushSigningReady = VapidPush::signingKey() !== null;
        $pushStates = $pushDeliveryEnabled ? DB::table('push_delivery_states')->selectRaw('last_result, COUNT(*) AS total')->groupBy('last_result')->pluck('total', 'last_result') : collect();

        return response()->view('admin.notifications', compact('days', 'metrics', 'preferencesEnabled', 'paused', 'eventsEnabled', 'ownerEnabled', 'ownerMetrics', 'pushEnabled', 'pushKeyConfigured', 'pushCount', 'pushDeliveryEnabled', 'pushSigningReady', 'pushStates', 'broadcastEnabled', 'announcementDelivery', 'announcementHistory', 'announcementMetrics'))
            ->header('Cache-Control', 'private, no-store');
    }
}
