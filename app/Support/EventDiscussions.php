<?php

namespace App\Support;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Site;
use App\Models\User;
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
        $author = User::findOrFail($event->author_id);
        if (PublicationPolicy::allows('discussions', $author, $event->location_id)) {
            $thread->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
            OwnerNotifications::record($thread->author_id, 'discussion', $thread->id, 'approved');
            Audit::record('community.thread.auto-approved', null, context: ['thread' => $thread->id, 'event' => $event->id]);
        }
    }

    public static function publicThread(Site $site, CommunityEvent $event): ?CommunityThread
    {
        $community = app(Community::class);
        if (! self::enabled() || ! $community->enabled($site, 'community') || ! $community->enabled($site, 'events')
            || ! in_array($event->status, ['approved', 'cancelled'], true)) {
            return null;
        }

        return $community->threads($site->location_id)
            ->where('location_id', $event->location_id)->where('author_id', $event->author_id)
            ->whereIn('id', DB::table('community_event_discussions')->where('event_id', $event->id)->select('thread_id'))->first();
    }

    public static function publicEvent(Site $site, CommunityThread $thread): ?CommunityEvent
    {
        $community = app(Community::class);
        if (! self::enabled() || ! $community->enabled($site, 'events') || ! $community->enabled($site, 'community') || $thread->status !== 'approved') {
            return null;
        }

        return $community->events($site->location_id)->whereIn('status', ['approved', 'cancelled'])
            ->where('location_id', $thread->location_id)->where('author_id', $thread->author_id)
            ->whereIn('id', DB::table('community_event_discussions')->where('thread_id', $thread->id)->select('event_id'))->first();
    }

    /** Public discussion IDs for the current bounded page of visible events. */
    public static function publicThreadsForEvents(Site $site, array $ids): array
    {
        $community = app(Community::class);
        if ($ids === [] || ! self::enabled() || ! $community->enabled($site, 'events') || ! $community->enabled($site, 'community')) {
            return [];
        }

        return DB::table('community_event_discussions as links')
            ->join('community_events as events', 'events.id', '=', 'links.event_id')
            ->join('community_threads as threads', 'threads.id', '=', 'links.thread_id')
            ->whereIn('links.event_id', $ids)->where('threads.status', 'approved')
            ->whereIn('events.status', ['approved', 'cancelled'])
            ->whereColumn('events.location_id', 'threads.location_id')->whereColumn('events.author_id', 'threads.author_id')
            ->when($site->location_id, fn ($query, $id) => $query->where('events.location_id', $id))
            ->pluck('links.thread_id', 'links.event_id')->all();
    }

    /** Batch classification for already-authorized notification rows. */
    public static function publicThreadIds(Site $site, array $ids): array
    {
        $community = app(Community::class);
        if ($ids === [] || ! self::enabled() || ! $community->enabled($site, 'events') || ! $community->enabled($site, 'community')) {
            return [];
        }

        return DB::table('community_event_discussions as links')
            ->join('community_events as events', 'events.id', '=', 'links.event_id')
            ->join('community_threads as threads', 'threads.id', '=', 'links.thread_id')
            ->whereIn('links.thread_id', $ids)->where('threads.status', 'approved')
            ->whereIn('events.status', ['approved', 'cancelled'])
            ->whereColumn('events.location_id', 'threads.location_id')->whereColumn('events.author_id', 'threads.author_id')
            ->when($site->location_id, fn ($query, $id) => $query->where('threads.location_id', $id))
            ->pluck('links.thread_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function approvedEventAuthor(CommunityThread $thread): ?int
    {
        if (! self::enabled() || $thread->status !== 'approved') {
            return null;
        }

        $author = DB::table('community_event_discussions as links')
            ->join('community_events as events', 'events.id', '=', 'links.event_id')
            ->where('links.thread_id', $thread->id)->where('events.status', 'approved')
            ->where('events.location_id', $thread->location_id)->where('events.author_id', $thread->author_id)
            ->value('events.author_id');

        return $author === null ? null : (int) $author;
    }

    public static function pendingReview(Site $site, CommunityThread $thread): bool
    {
        $community = app(Community::class);
        if (! self::enabled() || ! $community->enabled($site, 'events') || ! $community->enabled($site, 'community')
            || $thread->status !== 'approved' || ($site->location_id && $thread->location_id !== $site->location_id)) {
            return false;
        }

        // Expose only a review-state indicator, never hidden venue, dates, ID,
        // external ticket link or moderation notes.
        return CommunityEvent::where('status', 'pending')->where('author_id', $thread->author_id)
            ->where('location_id', $thread->location_id)
            ->whereIn('id', DB::table('community_event_discussions')->where('thread_id', $thread->id)->select('event_id'))->exists();
    }
}
