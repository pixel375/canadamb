<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class NetworkSettings
{
    public const MODULES = ['news' => 'News', 'businesses' => 'Businesses', 'marketplace' => 'Marketplace',
        'real-estate' => 'Real Estate', 'jobs' => 'Jobs', 'community' => 'Community', 'events' => 'Events'];

    public const OPERATIONAL_MODULES = ['news' => 'News', 'marketplace' => 'Marketplace', 'businesses' => 'Business directory',
        'community' => 'Community forums', 'events' => 'Events', 'jobs' => 'Jobs'];

    public static function defaults(): array
    {
        return [
            'preset' => 'blue',
            'pwa_install_enabled' => true,
            'palette' => Themes::preset('blue'),
            'modules' => array_fill_keys(array_keys(self::OPERATIONAL_MODULES), true),
            'permitted_module_overrides' => array_keys(self::OPERATIONAL_MODULES),
        ];
    }

    public function published(): array
    {
        $attributes = request()->attributes;
        if ($attributes->has('site')) {
            if (! $attributes->has('published_network_settings')) {
                $attributes->set('published_network_settings', $this->revision($this->state()->published_id));
            }

            return $attributes->get('published_network_settings');
        }

        return $this->revision($this->state()->published_id);
    }

    public function registrationAllowed(): bool
    {
        return ! config('network.prelaunch') || ($this->published()['prelaunch_test_registration_enabled'] ?? false) === true;
    }

    public function forSite(Site $site, ?array $settings = null): array
    {
        $settings ??= $this->published();
        foreach ($settings['permitted_module_overrides'] as $module) {
            if (isset($site->overrides['modules'][$module])) {
                // Site overrides can disable a globally available module.
                $settings['modules'][$module] = ($settings['modules'][$module] ?? false) && $site->overrides['modules'][$module];
            }
        }

        return $settings;
    }

    public function publishedForSubmission(): array
    {
        $revision = DB::table('network_configuration')
            ->join('configuration_revisions', 'configuration_revisions.id', '=', 'network_configuration.published_id')
            ->where('network_configuration.id', 1)->sharedLock()
            ->firstOrFail(['configuration_revisions.id', 'configuration_revisions.settings']);

        return ['id' => (int) $revision->id, 'settings' => json_decode($revision->settings, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function state(): stdClass
    {
        return DB::table('network_configuration')->where('id', 1)->firstOrFail();
    }

    public function revision(int $id): array
    {
        return Cache::remember("network-settings:v1:revision:$id", now()->addHour(), function () use ($id) {
            $revision = DB::table('configuration_revisions')->where('id', $id)->firstOrFail();

            return json_decode($revision->settings, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    public function saveDraft(int $actor, Closure $change): void
    {
        DB::transaction(function () use ($actor, $change) {
            $state = $this->lockedState();
            $revision = DB::table('configuration_revisions')->where('id', $state->draft_id ?? $state->published_id)->firstOrFail();
            $settings = $change(json_decode($revision->settings, true, flags: JSON_THROW_ON_ERROR));
            $id = DB::table('configuration_revisions')->insertGetId([
                'actor_id' => $actor,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('network_configuration')->where('id', 1)->update(['draft_id' => $id]);
            Audit::record('configuration.draft', $actor, context: ['revision' => $id]);
        }, attempts: 3);
    }

    public function publish(int $actor, bool $rollback = false, ?int $expectedRevision = null): void
    {
        DB::transaction(function () use ($actor, $rollback, $expectedRevision) {
            $state = $this->lockedState();
            $target = $rollback ? $state->previous_id : $state->draft_id;
            if ($target === null) {
                throw ValidationException::withMessages(['theme' => 'No revision is available for that action.']);
            }
            abort_if($expectedRevision !== null && $expectedRevision !== (int) $target, 409, 'The configuration changed. Reload to preview the latest revision.');

            $current = $this->revision($state->published_id);
            $proposed = $this->revision($target);
            $before = ApprovalPolicy::networkModes($current);
            $after = ApprovalPolicy::networkModes($proposed);
            $beforeTypes = ApprovalPolicy::automaticTypes($current);
            $afterTypes = ApprovalPolicy::automaticTypes($proposed);
            $approvalChanges = $before !== $after || $beforeTypes !== $afterTypes;
            if ($approvalChanges || in_array(ApprovalPolicy::AUTO, $after, true)) {
                abort_unless(User::whereKey($actor)->where('role', 'super_admin')->whereNotNull('email_verified_at')->exists(), 403);
            }
            if ($approvalChanges && $expectedRevision === null) {
                throw ValidationException::withMessages(['revision' => 'Preview the approval policy revision on the Global settings page before publishing or rolling back.']);
            }

            DB::table('network_configuration')->where('id', 1)->update([
                'published_id' => $target,
                'previous_id' => $state->published_id,
                'draft_id' => $rollback ? $state->draft_id : null,
                'version' => $state->version + 1,
            ]);
            $context = [
                'before' => $state->published_id, 'after' => $target, 'version' => $state->version + 1,
            ];
            if ($before !== $after) {
                $context['approval_policies'] = ['before' => $before, 'after' => $after];
            }
            if ($beforeTypes !== $afterTypes) {
                $context['auto_approval_types'] = ['before' => array_keys($beforeTypes), 'after' => array_keys($afterTypes)];
            }
            Audit::record($rollback ? 'configuration.rollback' : 'configuration.publish', $actor, context: $context);
        }, attempts: 3);
    }

    private function lockedState(): stdClass
    {
        return DB::table('network_configuration')->where('id', 1)->lockForUpdate()->firstOrFail();
    }
}
