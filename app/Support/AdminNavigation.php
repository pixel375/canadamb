<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AdminNavigation
{
    public static function groups(User $user): array
    {
        $network = Gate::forUser($user)->allows('manage-network');
        $settings = Gate::forUser($user)->allows('manage-theme');
        $news = Gate::forUser($user)->allows('manage-news');
        $groups = [];
        if ($network) {
            $groups['Workspace'] = [
                ['/admin', 'Network overview', 'home'],
                ['/admin/communities', 'Community websites', 'map'],
            ];
            $groups['Content & safety'] = array_values(array_filter([
                ['/admin/moderation', 'Marketplace review', 'marketplace'],
                ['/admin/community', 'Discussions & events', 'discussions'],
                ['/admin/jobs', 'Job moderation', 'briefcase'],
                config('network.property_drafts') ? ['/admin/properties', 'Private property review', 'house'] : null,
                ContentReports::enabled() ? ['/admin/content-reports', 'Content reports', 'shield'] : null,
                $settings ? ['/admin/publication-policy', 'Moderation policy', 'settings'] : null,
            ]));
            $groups['Business management'] = array_values(array_filter([
                ['/admin/business-review', 'Business review', 'building'],
                ['/admin/business-ownership', 'Ownership directory', 'person'],
                BusinessChanges::enabled() ? ['/admin/business-changes', 'Change requests', 'settings'] : null,
                BusinessAccess::enabled() ? ['/admin/business-transfers', 'Access transfers', 'person'] : null,
                BusinessDisputes::enabled() ? ['/admin/business-disputes', 'Ownership disputes', 'shield'] : null,
            ]));
        }
        if ($news) {
            $groups['Editorial'] = [
                ['/admin/news', 'News editorial', 'news'],
                ['/admin/news/feed-health', 'Feed health', 'clock'],
            ];
        }
        if ($settings) {
            $groups['Experience & configuration'] = [
                ['/admin/settings', 'Global settings', 'settings'],
                ['/admin/theme', 'Theme & appearance', 'moon'],
                ['/admin/seo', 'SEO & search visibility', 'search'],
                ['/admin/local-context', 'Local time & weather', 'clock'],
                ['/admin/notifications', 'Notifications', 'bell'],
            ];
            $groups['Commercial planning'] = array_values(array_filter([
                ['/admin/commercial', 'Advertising & subscriptions', 'tag'],
                CommercialRecords::enabled() ? ['/admin/advertising', 'Campaign requests', 'tag'] : null,
                CommercialRecords::enabled() ? ['/admin/advertising/schedule', 'Campaign schedule', 'calendar'] : null,
                CommercialRecords::enabled() ? ['/admin/entitlements', 'Business trials', 'check'] : null,
                TestCheckoutAttempts::enabled() ? ['/admin/commercial/test-ledger', 'Test payment ledger', 'shield'] : null,
            ]));
        }
        if ($network) {
            $groups['Operations & privacy'] = array_values(array_filter([
                ['/admin/readiness', 'Launch readiness', 'shield'],
                ['/admin/audit', 'Audit log', 'news'],
                $settings ? ['/admin/media-inventory', 'Media inventory', 'photo'] : null,
                $settings && PrivacyRequests::enabled() ? ['/admin/privacy-requests', 'Privacy requests', 'shield'] : null,
                $settings && config('network.prelaunch') ? ['/admin/test-accounts', 'Prelaunch test accounts', 'person'] : null,
            ]));
        }

        return $groups;
    }

    public static function active(array $groups, string $path): ?string
    {
        $path = '/'.ltrim($path, '/');
        $best = null;
        foreach ($groups as $links) {
            foreach ($links as [$url]) {
                if (($path === $url || ($url !== '/admin' && str_starts_with($path, $url.'/')))
                    && strlen($url) > strlen($best ?? '')) {
                    $best = $url;
                }
            }
        }

        return $best;
    }
}
