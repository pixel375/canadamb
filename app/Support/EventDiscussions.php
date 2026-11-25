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

    /** Check existing links even when the feature is currently switched off. */
    public static function linkedEventIds(array $ids): array
    {
        return Schema::hasTable('community_event_discussions')
            ? DB::table('community_event_discussions')->whereIn('event_id', $ids)->pluck('event_id')->all()
            : [];
    }

    public static function eventIdsForThreads(array $ids): array
    {
        return Schema::hasTable('community_event_discussions')
            ? DB::table('community_event_discussions')->whereIn('thread_id', $ids)->pluck('event_id', 'thread_id')->all()
            : [];
    }

    public static function linkedThreads(array $ids): array
    {
        if (! Schema::hasTable('community_event_discussions')) {
            return [];
        }
        $links = DB::table('community_event_discussions')->whereIn('event_id', $ids)->pluck('thread_id', 'event_id');
        $threads = CommunityThread::whereIn('id', $links->values())->get()->keyBy('id');

        return $links->map(fn ($id) => $threads->get($id))->all();
    }

    public static function linkedThread(CommunityEvent $event, bool $lock = false): ?CommunityThread
    {
        if (! Schema::hasTable('community_event_discussions')) {
            return null;
        }
        $id = DB::table('community_event_discussions')->where('event_id', $event->id)->value('thread_id');

        return $id ? CommunityThread::when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id) : null;
    }

    public static function editable(CommunityEvent $event, CommunityThread $thread): bool
    {
        return self::enabled() && ! $thread->locked && in_array($thread->status, ['pending', 'approved', 'rejected'], true)
            && $event->author_id === $thread->author_id && $event->location_id === $thread->location_id;
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
