<?php

namespace App\Http\Controllers;

use App\Support\AccountActivity;
use App\Support\Community;
use Illuminate\Http\Request;

final class AccountNotificationsController extends Controller
{
    public function index(Request $request, AccountActivity $activity)
    {
        $site = $request->attributes->get('site');
        $user = $request->user();
        $counts = $activity->counts($user, $site);
        $discussionsEnabled = app(Community::class)->enabled($site, 'community');
        $recent = $discussionsEnabled
            ? $activity->discussionNotifications($user, $site)
                ->select('notification.id', 'notification.reply_id', 'notification.read_at', 'notification.created_at', 'thread.id as thread_id', 'thread.title')
                ->orderByDesc('notification.id')->limit(8)->get()
            : collect();

        return view('account.notifications', compact('counts', 'discussionsEnabled', 'recent'));
    }
}
