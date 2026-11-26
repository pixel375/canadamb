<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountActivity;
use App\Support\Community;
use App\Support\JobSearches;
use App\Support\NotificationPreferences;
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
            'version' => 'nullable|string|size:64',
        ]);
        DB::transaction(function () use ($request, $data) {
            $member = $request->user()->id;
            User::lockForUpdate()->findOrFail($member);
            $current = NotificationPreferences::forMember($member);
            if (isset($data['version']) && ! hash_equals(NotificationPreferences::version($current), $data['version'])) {
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

        $directReplies = ReplyLinks::directNotificationIds($user->id, $recent->pluck('reply_id')->all());
        $preferencesEnabled = NotificationPreferences::enabled();
        $preferences = NotificationPreferences::forMember($user->id);
        $jobAlertsEnabled = JobSearches::enabled($site) && $preferences['job_summary'];
        $matchingSearches = $jobAlertsEnabled ? JobSearches::alertingSearchCount($user->id, $site) : 0;

        return response()->view('account.notifications', compact('counts', 'discussionsEnabled', 'recent', 'preferencesEnabled', 'preferences', 'jobAlertsEnabled', 'matchingSearches', 'directReplies'))
            ->header('Cache-Control', 'private, no-store');
    }
}
