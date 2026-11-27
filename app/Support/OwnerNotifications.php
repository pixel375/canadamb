<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OwnerNotifications
{
    private const TARGETS = [
        'listing' => ['listings', 'user_id', 'marketplace', '/account/listings'],
        'discussion' => ['community_threads', 'author_id', 'community', '/account/discussions'],
        'reply' => ['community_replies', 'author_id', 'community', '/account/replies'],
        'event' => ['community_events', 'author_id', 'events', '/account/events'],
        'job' => ['job_postings', 'author_id', 'jobs', '/account/businesses'],
        'business-update' => ['business_updates', 'author_id', 'businesses', '/account/businesses'],
        'business-claim' => ['business_claims', 'user_id', 'businesses', '/account/businesses'],
        'property' => ['property_listings', 'owner_id', 'real-estate', '/account/properties'],
    ];

    public static function enabled(): bool
    {
        return (bool) config('network.owner_notifications') && Schema::hasTable('owner_notifications');
    }

    // Only call inside the transaction performing a locked status transition.
    // No user-supplied title, moderator identity, private reason or body is copied.
    public static function record(?int $member, string $kind, int $target, string $decision): void
    {
        if (! $member || ! self::enabled()) {
            return;
        }
        if (! isset(self::TARGETS[$kind]) || ! in_array($decision, ['approved', 'rejected', 'removed', 'expired', 'reviewed', 'changes_requested', 'superseded'], true)) {
            throw new \InvalidArgumentException('Unsupported owner notification.');
        }
        DB::table('owner_notifications')->insert([
            'user_id' => $member, 'kind' => $kind, 'target_id' => $target, 'decision' => $decision, 'created_at' => now(),
        ]);
    }

    public static function visible(User $user, Site $site): Builder
    {
        $modules = app(NetworkSettings::class)->forSite($site)['modules'];

        return DB::table('owner_notifications as alert')->where('alert.user_id', $user->id)
            ->where(function (Builder $query) use ($site, $modules) {
                $query->whereRaw('1 = 0');
                foreach (self::TARGETS as $kind => [$table, $owner, $module]) {
                    if ($kind === 'property' ? ! config('network.property_drafts') : ! ($modules[$module] ?? false)) {
                        continue;
                    }
                    $query->orWhere(function (Builder $type) use ($site, $kind, $table, $owner) {
                        $type->where('alert.kind', $kind)->whereExists(function (Builder $target) use ($site, $kind, $table, $owner) {
                            $target->selectRaw('1')->from($table.' as target')
                                ->whereColumn('target.id', 'alert.target_id')->whereColumn('target.'.$owner, 'alert.user_id');
                            if ($kind === 'reply') {
                                $target->join('community_threads as parent', 'parent.id', '=', 'target.thread_id');
                            }
                            if (in_array($kind, ['job', 'business-update'], true)) {
                                $target->join('business_locations as branch', 'branch.id', '=', 'target.business_location_id')
                                    ->whereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('business_memberships as membership')
                                        ->whereColumn('membership.business_id', 'branch.business_id')
                                        ->whereColumn('membership.user_id', 'alert.user_id')
                                        ->whereIn('membership.role', $kind === 'job' ? ['owner', 'manager'] : ['owner', 'manager', 'editor']));
                            }
                            if ($kind === 'business-claim') {
                                // Claim history belongs to the claimant, not the current business owner.
                                if ($site->location_id !== null) {
                                    $target->whereExists(fn (Builder $branch) => $branch->selectRaw('1')->from('business_locations')
                                        ->whereColumn('business_locations.business_id', 'target.business_id')->where('location_id', $site->location_id));
                                }
                            } elseif ($site->location_id !== null) {
                                $locationTable = match ($kind) {
                                    'reply' => 'parent', 'job', 'business-update' => 'branch', default => 'target',
                                };
                                $target->where($locationTable.'.location_id', $site->location_id);
                            }
                        });
                    });
                }
            });
    }

    public static function destination(string $kind): string
    {
        return self::TARGETS[$kind][3];
    }

    public static function description(string $kind, string $decision): string
    {
        $subject = match ($kind) {
            'listing' => 'Marketplace listing',
            'discussion' => 'discussion',
            'reply' => 'comment',
            'event' => 'event',
            'job' => 'job posting',
            'business-update' => 'business update',
            'business-claim' => 'business ownership claim',
            'property' => 'private property draft',
        };

        return match ($decision) {
            'approved' => 'Your '.$subject.' was approved.',
            'rejected' => 'Your '.$subject.' was not approved.',
            'removed' => 'Your '.$subject.' was removed.',
            'expired' => 'Your '.$subject.' expired.',
            'reviewed' => 'Your '.$subject.' was reviewed. This is not publication approval.',
            'changes_requested' => 'Changes were requested for your '.$subject.'.',
            'superseded' => 'Your '.$subject.' is no longer pending.',
        };
    }
}
