<?php

namespace App\Support;

use App\Models\Site;
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

    public function forSite(Site $site): array
    {
        $settings = $this->published();
        foreach ($settings['permitted_module_overrides'] as $module) {
            if (isset($site->overrides['modules'][$module])) {
                // Site overrides can disable a globally available module.
                $settings['modules'][$module] = ($settings['modules'][$module] ?? false) && $site->overrides['modules'][$module];
            }
        }

        return $settings;
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

    public static function stateVersion(stdClass $state): string
    {
        return hash('sha256', json_encode([$state->published_id, $state->draft_id, $state->previous_id, $state->version], JSON_THROW_ON_ERROR));
    }

    private function checkState(stdClass $state, ?string $expected): void
    {
        if ($expected !== null && ! hash_equals(self::stateVersion($state), $expected)) {
            throw ValidationException::withMessages(['configuration_version' => 'Network configuration changed. Reload and review the current draft before continuing.']);
        }
    }

    public function saveDraft(int $actor, Closure $change, ?string $expected = null): void
    {
        DB::transaction(function () use ($actor, $change, $expected) {
            $state = $this->lockedState();
            $this->checkState($state, $expected);
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

    public function publish(int $actor, bool $rollback = false, ?string $expected = null): void
    {
        DB::transaction(function () use ($actor, $rollback, $expected) {
            $state = $this->lockedState();
            $this->checkState($state, $expected);
            $target = $rollback ? $state->previous_id : $state->draft_id;
            if ($target === null) {
                throw ValidationException::withMessages(['theme' => 'No revision is available for that action.']);
            }

            DB::table('network_configuration')->where('id', 1)->update([
                'published_id' => $target,
                'previous_id' => $state->published_id,
                'draft_id' => $rollback ? $state->draft_id : null,
                'version' => $state->version + 1,
            ]);
            Audit::record($rollback ? 'configuration.rollback' : 'configuration.publish', $actor, context: [
                'before' => $state->published_id, 'after' => $target, 'version' => $state->version + 1,
            ]);
        }, attempts: 3);
    }

    public function discardDraft(int $actor, string $expected): void
    {
        DB::transaction(function () use ($actor, $expected) {
            $state = $this->lockedState();
            $this->checkState($state, $expected);
            if ($state->draft_id === null) {
                throw ValidationException::withMessages(['configuration_version' => 'No pending configuration draft is available.']);
            }

            DB::table('network_configuration')->where('id', 1)->update(['draft_id' => null]);
            Audit::record('configuration.discard', $actor, context: ['revision' => $state->draft_id]);
        }, attempts: 3);
    }

    private function lockedState(): stdClass
    {
        return DB::table('network_configuration')->where('id', 1)->lockForUpdate()->firstOrFail();
    }
}
