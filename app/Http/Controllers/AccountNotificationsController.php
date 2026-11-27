<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountActivity;
use App\Support\Announcements;
use App\Support\Audit;
use App\Support\BroadcastConsent;
use App\Support\Community;
use App\Support\EventDiscussions;
use App\Support\JobSearches;
use App\Support\NotificationPreferences;
use App\Support\OwnerNotifications;
use App\Support\PushDelivery;
use App\Support\PushDevices;
use App\Support\ReplyLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountNotificationsController extends Controller
{
    public function preferences(Request $request)
    {
        abort_unless(NotificationPreferences::enabled(), 404);
        $data = $request->validate([
            'discussion_replies' => 'required|boolean',
            'job_summary' => 'required|boolean',
            'version' => 'required|string|size:64',
        ]);
        DB::transaction(function () use ($request, $data) {
            $member = $request->user()->id;
            User::lockForUpdate()->findOrFail($member);
            $current = NotificationPreferences::forMember($member);
            if (! hash_equals(NotificationPreferences::version($current), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Your notification preferences changed. Reload before saving.']);
            }
            $next = ['discussion_replies' => (bool) $data['discussion_replies'], 'job_summary' => (bool) $data['job_summary']];
            if ($next !== $current) {
                DB::table('notification_preferences')->updateOrInsert(['user_id' => $member], $next + ['updated_at' => now()]);
            }
        }, attempts: 3);

        return redirect('/account/notifications')->with('status', 'Notification preferences saved across the network.');
    }

    public function counts(Request $request, AccountActivity $activity)
    {
        return response()->json($activity->counts($request->user(), $request->attributes->get('site')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function readOwnerAlert(Request $request, int $notification)
    {
        abort_unless(OwnerNotifications::enabled(), 404);
        DB::transaction(function () use ($request, $notification) {
            $query = OwnerNotifications::visible($request->user(), $request->attributes->get('site'))->where('alert.id', $notification);
            $alert = (clone $query)->lockForUpdate()->first();
            abort_unless($alert, 404);
            if (! $alert->read_at) {
                DB::table('owner_notifications')->where('id', $notification)->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
            }
        });

        return redirect('/account/notifications');
    }

    public function readAnnouncement(Request $request, int $receipt)
    {
        abort_unless(Announcements::enabled(), 404);
        abort_unless(Announcements::visible($request->user()->id)->where('receipt.id', $receipt)->exists(), 404);
        DB::table('announcement_receipts')->where('id', $receipt)->where('user_id', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return redirect('/account/notifications');
    }

    public function announcementConsent(Request $request)
    {
        abort_unless(BroadcastConsent::enabled(), 404);
        $data = $request->validate(['opted_in' => 'required|boolean', 'version' => 'required|string|size:64']);
        $member = $request->user()->id;
        $next = (bool) $data['opted_in'];
        DB::transaction(function () use ($member, $next, $data) {
            User::lockForUpdate()->findOrFail($member);
            $current = BroadcastConsent::optedIn($member);
            if (! hash_equals(BroadcastConsent::version($current), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Your announcement preference changed. Reload before saving.']);
            }
            if ($current !== $next) {
                DB::table('broadcast_consents')->updateOrInsert(['user_id' => $member], ['opted_in' => $next, 'updated_at' => now()]);
                Audit::record('broadcast.consent-changed', $member, context: ['opted_in' => $next]);
            }
        }, attempts: 3);

        return redirect('/account/notifications')->with('status', 'Announcement preference saved.');
    }

    public function index(Request $request, AccountActivity $activity)
    {
        $site = $request->attributes->get('site');
        $user = $request->user();
        $counts = $activity->counts($user, $site);
        $discussionsEnabled = app(Community::class)->enabled($site, 'community');
        $recent = $discussionsEnabled
            ? $activity->discussionNotifications($user, $site)
                ->select('notification.id', 'notification.reply_id', 'notification.read_at', 'notification.created_at', 'thread.id as thread_id', 'thread.title', 'actor.name as actor_name')
                ->orderByRaw('CASE WHEN notification.read_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('notification.id')->limit(8)->get()
            : collect();

        $ownerEnabled = OwnerNotifications::enabled();
        $ownerQuery = $ownerEnabled ? OwnerNotifications::visible($user, $site) : null;
        $ownerUnread = $ownerQuery ? (clone $ownerQuery)->whereNull('alert.read_at')->count() : 0;
        $ownerAlerts = $ownerQuery ? $ownerQuery->orderByRaw('CASE WHEN alert.read_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('alert.id')->paginate(15, pageName: 'updates') : null;
        $announcementQuery = Announcements::enabled() ? Announcements::visible($user->id) : null;
        $announcementUnread = $announcementQuery ? (clone $announcementQuery)->whereNull('receipt.read_at')->count() : 0;
        $announcements = $announcementQuery ? $announcementQuery->orderByRaw('CASE WHEN receipt.read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('receipt.id')->paginate(15, ['receipt.id', 'receipt.read_at', 'receipt.created_at', 'announcement.title', 'announcement.body'], 'announcements') : null;
        $replyUnread = max(0, $counts['notifications'] - $ownerUnread - $announcementUnread);
        $broadcastEnabled = BroadcastConsent::enabled();
        $broadcastOptedIn = BroadcastConsent::optedIn($user->id);
        $pushEnabled = PushDevices::enabled();
        $pushDelivery = PushDelivery::available($site);
        $pushKey = PushDevices::available($site) ? PushDevices::publicKey() : null;
        $pushDevices = $pushEnabled ? DB::table('push_devices')->join('sites', 'sites.id', '=', 'push_devices.site_id')
            ->where('push_devices.user_id', $user->id)->orderByDesc('push_devices.id')
            ->limit(10)->get(['push_devices.id', 'sites.name as site_name', 'push_devices.created_at']) : collect();
        $directReplies = ReplyLinks::directNotificationIds($user->id, $recent->pluck('reply_id')->all());
        $eventThreads = EventDiscussions::publicThreadIds($site, $recent->pluck('thread_id')->all());
        $preferencesEnabled = NotificationPreferences::enabled();
        $preferences = NotificationPreferences::forMember($user->id);
        $jobAlertsEnabled = JobSearches::enabled($site) && $preferences['job_summary'];
        $matchingSearches = $jobAlertsEnabled ? JobSearches::alertingSearchCount($user->id, $site) : 0;

        return response()->view('account.notifications', compact('counts', 'discussionsEnabled', 'recent', 'preferencesEnabled', 'preferences', 'jobAlertsEnabled', 'matchingSearches', 'directReplies', 'eventThreads', 'ownerEnabled', 'ownerAlerts', 'ownerUnread', 'replyUnread', 'pushEnabled', 'pushDelivery', 'pushKey', 'pushDevices', 'broadcastEnabled', 'broadcastOptedIn', 'announcements', 'announcementUnread'))
            ->header('Cache-Control', 'private, no-store');
    }
}
