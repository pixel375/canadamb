<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BroadcastConsent
{
    public static function enabled(): bool
    {
        return (bool) config('network.broadcast_previews') && Schema::hasTable('broadcast_consents');
    }

    public static function optedIn(int $member): bool
    {
        return self::enabled() && (bool) DB::table('broadcast_consents')->where('user_id', $member)->value('opted_in');
    }

    public static function audience(string $audience, array $members = []): Builder
    {
        return DB::table('broadcast_consents as consent')->join('users', 'users.id', '=', 'consent.user_id')
            ->where('consent.opted_in', true)->whereNotNull('users.email_verified_at')
            ->when($audience === 'business-owners', fn (Builder $query) => $query->whereExists(fn (Builder $membership) => $membership
                ->selectRaw('1')->from('business_memberships')->join('businesses', 'businesses.id', '=', 'business_memberships.business_id')
                ->whereColumn('business_memberships.user_id', 'users.id')->where('business_memberships.role', 'owner')->where('businesses.status', 'approved')))
            ->when($audience === 'selected', fn (Builder $query) => $query->whereIn('users.id', $members));
    }
}
