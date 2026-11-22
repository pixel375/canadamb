<?php

namespace App\Support;

use App\Models\BusinessLocation;
use App\Models\JobPosting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MessageContact
{
    public const ROLES = ['owner', 'manager'];

    public function eligibleMembers(int $business): Builder
    {
        return DB::table('business_memberships')
            ->join('users', 'users.id', '=', 'business_memberships.user_id')
            ->where('business_memberships.business_id', $business)
            ->whereIn('business_memberships.role', self::ROLES)
            ->whereNotNull('users.email_verified_at');
    }

    public function forBranch(BusinessLocation $branch): ?int
    {
        return $this->designatedMember($branch->business_id, $branch->message_contact_user_id);
    }

    public function forJob(JobPosting $job): ?int
    {
        return $this->designatedMember($job->branch->business_id, $job->message_contact_user_id);
    }

    private function designatedMember(int $business, ?int $user): ?int
    {
        return $user !== null && $this->eligibleMembers($business)->where('users.id', $user)->exists() ? $user : null;
    }

    /** Called in the transaction that holds the branch or job row lock. */
    public function setConsent(BusinessLocation|JobPosting $context, int $business, int $actor, bool $enabled, int $site): void
    {
        abort_unless($this->eligibleMembers($business)->where('users.id', $actor)->exists(), 404);
        $current = $context->message_contact_user_id;
        if ($current !== null && $current !== $actor && (! $enabled || $this->designatedMember($business, $current) !== null)) {
            throw ValidationException::withMessages(['enabled' => 'Another owner or manager is the assigned message contact.']);
        }

        $contact = $enabled ? $actor : null;
        if ($contact !== $current) {
            $context->forceFill(['message_contact_user_id' => $contact])->save();
            Audit::record($enabled ? 'message.contact-enabled' : 'message.contact-disabled', $actor, $site, [
                'business' => $business,
                $context instanceof BusinessLocation ? 'branch' : 'job' => $context->id,
            ]);
        }
    }
}
