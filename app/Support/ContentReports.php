<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class ContentReports
{
    public const TYPES = ['discussion' => 'Discussion', 'reply' => 'Discussion reply', 'event' => 'Event', 'job' => 'Job posting', 'business' => 'Business profile', 'business-update' => 'Business update'];

    public const REASONS = ['spam' => 'Spam or scam', 'harassment' => 'Harassment or threats', 'privacy' => 'Personal information', 'misleading' => 'Misleading content', 'other' => 'Other concern'];

    public static function enabled(): bool
    {
        return (bool) config('network.content_reports') && Schema::hasTable('content_reports');
    }

    public static function visible(Site $site, string $type, int $id): Model
    {
        abort_unless(self::enabled(), 404);
        $community = app(Community::class);
        $module = match ($type) {
            'event' => 'events', 'job' => 'jobs', 'business', 'business-update' => 'businesses', default => 'community',
        };
        abort_unless($community->enabled($site, $module), 404);

        return match ($type) {
            'discussion' => $community->threads($site->location_id)->findOrFail($id),
            'reply' => CommunityReply::where('status', 'approved')->whereHas('thread', fn ($query) => $query->where('status', 'approved')
                ->when($site->location_id, fn ($thread, $location) => $thread->where('location_id', $location)))->findOrFail($id),
            'event' => $community->events($site->location_id)->whereIn('status', ['approved', 'cancelled'])->findOrFail($id),
            'job' => app(JobBoard::class)->visible($site->location_id)->findOrFail($id),
            'business' => app(BusinessDirectory::class)->visible($site)->findOrFail($id),
            'business-update' => app(BusinessDirectory::class)->updates($site->location_id)->findOrFail($id),
            default => abort(404),
        };
    }

    public static function label(Model $target): string
    {
        return $target->title ?? $target->name ?? 'Discussion reply';
    }

    public static function summary(Model $target): string
    {
        return (string) ($target->body ?? $target->description ?? '');
    }

    public static function conflicts(Model $target, int $reviewer): bool
    {
        if ((int) $target->author_id === $reviewer) {
            return true;
        }
        $business = $target instanceof Business ? $target : (($target instanceof JobPosting || $target instanceof BusinessUpdate) ? $target->branch?->business : null);

        return $business && $business->members()->whereKey($reviewer)->exists();
    }

    public static function forReview(string $type, int $id): ?Model
    {
        return match ($type) {
            'discussion' => CommunityThread::find($id),
            'reply' => CommunityReply::with('thread')->find($id),
            'event' => CommunityEvent::find($id),
            'job' => JobPosting::with('branch.business')->find($id),
            'business' => Business::find($id),
            'business-update' => BusinessUpdate::with('branch.business')->find($id),
            default => null,
        };
    }
}
