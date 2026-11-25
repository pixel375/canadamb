<?php

namespace App\Support;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EventDiscussions
{
    public static function enabled(): bool
    {
        return (bool) config('network.event_discussions') && Schema::hasTable('community_event_discussions');
    }

    public static function create(CommunityEvent $event): void
    {
        $thread = CommunityThread::create([
            'author_id' => $event->author_id,
            'location_id' => $event->location_id,
            'title' => $event->title,
            'body' => $event->body,
            'category' => 'general',
        ]);
        DB::table('community_event_discussions')->insert(['event_id' => $event->id, 'thread_id' => $thread->id]);
    }

    public static function publicThread(Site $site, CommunityEvent $event): ?CommunityThread
    {
        $community = app(Community::class);
        if (! self::enabled() || ! $community->enabled($site, 'community') || ! $community->enabled($site, 'events')
            || ! in_array($event->status, ['approved', 'cancelled'], true)) {
            return null;
        }

        return $community->threads($site->location_id)
            ->where('location_id', $event->location_id)
            ->whereIn('id', DB::table('community_event_discussions')->where('event_id', $event->id)->select('thread_id'))->first();
    }

    public static function publicEvent(Site $site, CommunityThread $thread): ?CommunityEvent
    {
        $community = app(Community::class);
        if (! self::enabled() || ! $community->enabled($site, 'events') || ! $community->enabled($site, 'community') || $thread->status !== 'approved') {
            return null;
        }

        return $community->events($site->location_id)->whereIn('status', ['approved', 'cancelled'])
            ->where('location_id', $thread->location_id)
            ->whereIn('id', DB::table('community_event_discussions')->where('thread_id', $thread->id)->select('event_id'))->first();
    }

    public static function eventPath(Site $site, CommunityEvent $event): string
    {
        $thread = self::publicThread($site, $event);

        return $thread ? '/community/'.$thread->id : '/events/'.$event->id;
    }
}
