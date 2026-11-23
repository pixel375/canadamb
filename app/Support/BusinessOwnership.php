<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

final class BusinessOwnership
{
    public const DISPUTE_HOLD = ['pending_review', 'on_hold'];

    public const TRANSFER_PENDING = ['awaiting_acceptance', 'pending_review'];

    public static function onHold(int $business): bool
    {
        return DB::table('business_ownership_cases')
            ->where(['business_id' => $business, 'type' => 'dispute'])
            ->whereIn('status', self::DISPUTE_HOLD)->exists();
    }

    /**
     * Use after locking the business row, and before changing ownership, staff
     * memberships, branch details, or their pending invitations.
     */
    public static function requireUncontested(int $business): void
    {
        abort_if(self::onHold($business), 409, 'Ownership is under independent review. Role and location changes are temporarily paused.');
    }

    public static function belongsToPortal(int $business, Site $site): bool
    {
        return $site->location_id === null || DB::table('business_locations')
            ->where(['business_id' => $business, 'location_id' => $site->location_id])->exists();
    }

    public static function memberIsOwner(int $business, int $user): bool
    {
        return DB::table('business_memberships')
            ->where(['business_id' => $business, 'user_id' => $user, 'role' => 'owner'])->exists();
    }
}
