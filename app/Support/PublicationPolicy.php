<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;

final class PublicationPolicy
{
    public const TYPES = ['listings' => 'New Marketplace listings', 'discussions' => 'New discussions', 'replies' => 'New comments and replies', 'events' => 'New calendar events'];

    public const MODES = ['manual' => 'Manual moderation', 'established' => 'Verified members with accounts at least 7 days old', 'verified' => 'All email-verified members'];

    public static function allows(string $type, User $user, ?int $locationId): bool
    {
        if (! array_key_exists($type, self::TYPES) || ! $user->hasVerifiedEmail()) {
            return false;
        }

        $mode = app(NetworkSettings::class)->published()['publication_policy'][$type] ?? 'manual';

        $eligible = $mode === 'verified' || ($mode === 'established' && $user->created_at?->lte(now()->subDays(7)));
        if (! $eligible || $locationId === null) {
            return $eligible;
        }

        // The content's location, not the requesting hostname, determines stricter local review.
        $local = Site::where('location_id', $locationId)->first(['overrides']);

        return ($local?->overrides['manual_publication'][$type] ?? false) !== true;
    }
}
