<?php

namespace App\Support;

use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class ContentReports
{
    public const TYPES = ['discussion' => 'Discussion', 'reply' => 'Discussion reply', 'event' => 'Event'];

    public const REASONS = ['spam' => 'Spam or scam', 'harassment' => 'Harassment or threats', 'privacy' => 'Personal information', 'misleading' => 'Misleading content', 'other' => 'Other concern'];

    public static function enabled(): bool
    {
        return (bool) config('network.content_reports') && Schema::hasTable('content_reports');
    }

    public static function visible(Site $site, string $type, int $id): CommunityThread|CommunityReply|CommunityEvent
    {
        abort_unless(self::enabled(), 404);
        $community = app(Community::class);
        abort_unless($community->enabled($site, $type === 'event' ? 'events' : 'community'), 404);

        return match ($type) {
            'discussion' => $community->threads($site->location_id)->findOrFail($id),
            'reply' => CommunityReply::where('status', 'approved')->whereHas('thread', fn ($query) => $query->where('status', 'approved')
                ->when($site->location_id, fn ($thread, $location) => $thread->where('location_id', $location)))->findOrFail($id),
            'event' => $community->events($site->location_id)->whereIn('status', ['approved', 'cancelled'])->findOrFail($id),
            default => abort(404),
        };
    }

    public static function forReview(string $type, int $id): CommunityThread|CommunityReply|CommunityEvent|null
    {
        return match ($type) {
            'discussion' => CommunityThread::find($id),
            'reply' => CommunityReply::with('thread')->find($id),
            'event' => CommunityEvent::find($id),
            default => null,
        };
    }
}
