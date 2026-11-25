<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Message;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class ContentReports
{
    public const TYPES = ['discussion' => 'Discussion', 'reply' => 'Discussion reply', 'event' => 'Event', 'job' => 'Job posting', 'business' => 'Business profile', 'business-update' => 'Business update', 'profile' => 'Member profile', 'message' => 'Private message', 'message-photo' => 'Private message photo'];

    public const REASONS = ['spam' => 'Spam or scam', 'harassment' => 'Harassment or threats', 'privacy' => 'Personal information', 'misleading' => 'Misleading content', 'other' => 'Other concern'];

    public static function enabled(): bool
    {
        return (bool) config('network.content_reports') && Schema::hasTable('content_reports');
    }

    public static function visible(Site $site, string $type, int $id, int $member): Model
    {
        abort_unless(self::enabled(), 404);
        // Profiles and inboxes are network-wide, rather than geographically scoped.
        if ($type === 'profile') {
            return User::whereKey($id)->where('id', '!=', $member)->firstOrFail();
        }
        if (self::privateType($type)) {
            abort_if($type === 'message-photo' && ! MessageAttachments::enabled(), 404);

            return Message::whereKey($id)->where('sender_id', '!=', $member)
                ->whereHas('conversation', fn ($query) => $query->forMember($member))
                ->when($type === 'message-photo', fn ($query) => $query->whereHas('attachment'))->firstOrFail();
        }

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

    public static function privateType(string $type): bool
    {
        return in_array($type, ['message', 'message-photo'], true);
    }

    public static function label(Model $target): string
    {
        return $target instanceof Message ? 'Reported private message' : ($target->title ?? $target->name ?? 'Discussion reply');
    }

    public static function summary(Model $target): string
    {
        return $target instanceof User ? 'Public display name only.' : (string) ($target->body ?? $target->description ?? '');
    }

    public static function conflicts(Model $target, int $reviewer): bool
    {
        if ($target instanceof User) {
            return (int) $target->id === $reviewer;
        }
        if ($target instanceof Message) {
            return ! $target->conversation || in_array($reviewer, [(int) $target->conversation->buyer_id, (int) $target->conversation->seller_id], true);
        }
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
            'profile' => User::select('id', 'name')->find($id),
            'message', 'message-photo' => Message::with('conversation:id,buyer_id,seller_id')->find($id),
            default => null,
        };
    }
}
