<?php

namespace App\Support;

use App\Models\Site;

final class ApprovalPolicy
{
    public const MANUAL = 'manual_review';

    public const AUTO = 'auto_approve';

    public const TYPES = [
        'listing' => 'Marketplace listings',
        'thread' => 'Discussion posts',
        'reply' => 'Discussion replies',
        'event' => 'Events',
        'business-update' => 'Business updates',
        'job' => 'Jobs',
    ];

    private const MODULES = [
        'listing' => 'marketplace', 'thread' => 'community', 'reply' => 'community',
        'event' => 'events', 'business-update' => 'businesses', 'job' => 'jobs',
    ];

    private const AUDIT = [
        'listing' => ['listing.active', 'listing'],
        'thread' => ['community.thread.approve', 'thread'],
        'reply' => ['community.reply.approve', 'reply'],
        'event' => ['community.event.approve', 'event'],
        'business-update' => ['business.update-approved', 'update'],
        'job' => ['jobs.approve', 'job'],
    ];

    public function __construct(private readonly NetworkSettings $settings) {}

    public static function networkModes(array $settings): array
    {
        $modes = [];
        foreach (self::TYPES as $type => $label) {
            $modes[$type] = ($settings['approval_policies'][$type] ?? null) === self::AUTO ? self::AUTO : self::MANUAL;
        }

        return $modes;
    }

    public static function automaticTypes(array $settings): array
    {
        return array_filter(self::TYPES, fn ($type) => ($settings['approval_policies'][$type] ?? null) === self::AUTO
            && ($settings['modules'][self::MODULES[$type]] ?? false), ARRAY_FILTER_USE_KEY);
    }

    /** Resolve the current policy inside the same transaction as the new submission. */
    public function automaticRevision(Site $site, string $type, ?int $location): ?int
    {
        if (! isset(self::TYPES[$type])) {
            return null;
        }

        ['id' => $revision, 'settings' => $published] = $this->settings->publishedForSubmission();
        if (($published['approval_policies'][$type] ?? null) !== self::AUTO) {
            return null;
        }

        $origin = Site::query()->sharedLock()->findOrFail($site->id);
        if ($origin->status !== 'active' || ! $this->siteAllows($origin, $published, $type)) {
            return null;
        }

        if ($location !== null && $origin->location_id !== $location) {
            // A submission from CanadaMB still follows the destination portal's restrictions.
            $destination = Site::where('location_id', $location)->sharedLock()->first();
            if ($destination !== null && ! $this->siteAllows($destination, $published, $type)) {
                return null;
            }
        }

        return $revision;
    }

    public function recordAutomaticApproval(string $type, int $id, int $author, Site $site, int $revision, ?int $location): int
    {
        [$action, $resource] = self::AUDIT[$type];
        $audit = Audit::record($action, null, $site->id, [$resource => $id, 'approval_policy_revision' => $revision]);
        if ($type !== 'listing') {
            // Marketplace uses its status history as the notification source.
            NotificationInbox::record($author, $type.'.approved', $audit, $id, [$location]);
        }

        return $audit;
    }

    private function siteAllows(Site $site, array $published, string $type): bool
    {
        $overrides = $site->overrides['approval_policies'] ?? [];

        return is_array($overrides) && ! array_key_exists($type, $overrides)
            && ($this->settings->forSite($site, $published)['modules'][self::MODULES[$type]] ?? false);
    }
}
