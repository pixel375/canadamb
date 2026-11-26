<?php

namespace App\Support;

final class ConfigurationReview
{
    public static function changes(array $from, array $to): array
    {
        $rows = [];
        $add = function (string $label, string $before, string $after) use (&$rows) {
            if ($before !== $after) {
                $rows[] = ['label' => $label, 'before' => $before, 'after' => $after];
            }
        };
        $enabled = fn ($value) => $value ? 'Enabled' : 'Disabled';
        $preset = fn (array $value) => Themes::LABELS[$value['preset'] ?? 'blue'] ?? 'Custom';
        $add('Network theme', $preset($from), $preset($to));
        if (($from['palette'] ?? []) !== ($to['palette'] ?? [])) {
            $rows[] = ['label' => 'Theme palette', 'before' => 'Current colors', 'after' => 'Different colors'];
        }
        foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
            $add($label, $enabled($from['modules'][$key] ?? false), $enabled($to['modules'][$key] ?? false));
            $add($label.' · local hiding',
                in_array($key, $from['permitted_module_overrides'] ?? [], true) ? 'Allowed' : 'Not allowed',
                in_array($key, $to['permitted_module_overrides'] ?? [], true) ? 'Allowed' : 'Not allowed');
        }
        foreach (['pwa_install_enabled' => 'Web app installation', 'prelaunch_test_registration_enabled' => 'Prelaunch test registration',
            'local_clock_enabled' => 'Local clock', 'weather_enabled' => 'Weather'] as $key => $label) {
            $default = in_array($key, ['pwa_install_enabled', 'local_clock_enabled'], true);
            $add($label, $enabled($from[$key] ?? $default), $enabled($to[$key] ?? $default));
        }
        $add('Clock format', ($from['clock_format'] ?? '12') === '24' ? '24-hour' : '12-hour', ($to['clock_format'] ?? '12') === '24' ? '24-hour' : '12-hour');
        if (($from['weather_key_encrypted'] ?? null) !== ($to['weather_key_encrypted'] ?? null)) {
            $rows[] = ['label' => 'Weather credential', 'before' => empty($from['weather_key_encrypted']) ? 'Not configured' : 'Configured (hidden)',
                'after' => empty($to['weather_key_encrypted']) ? 'Removed' : 'Different credential (hidden)'];
        }
        foreach (PublicationPolicy::TYPES as $key => $label) {
            $add($label, PublicationPolicy::MODES[$from['publication_policy'][$key] ?? 'manual'] ?? 'Manual moderation',
                PublicationPolicy::MODES[$to['publication_policy'][$key] ?? 'manual'] ?? 'Manual moderation');
        }
        $fromCatalog = CommercialCatalog::fromSettings($from);
        $toCatalog = CommercialCatalog::fromSettings($to);
        $price = fn ($cents) => $cents === null ? 'Not configured' : 'CAD $'.number_format($cents / 100, 2);
        foreach (CommercialCatalog::PRODUCTS as $key => $product) {
            $add($product['name'].' rate', $price($fromCatalog[$key]['price_cents']), $price($toCatalog[$key]['price_cents']));
        }
        $known = ['preset', 'palette', 'modules', 'permitted_module_overrides', 'pwa_install_enabled', 'prelaunch_test_registration_enabled',
            'local_clock_enabled', 'clock_format', 'weather_enabled', 'weather_key_encrypted', 'publication_policy', 'commercial_catalog'];
        if (array_diff_key($from, array_flip($known)) !== array_diff_key($to, array_flip($known))) {
            $rows[] = ['label' => 'Other configuration', 'before' => 'Current values', 'after' => 'Other values changed (not displayed)'];
        }

        return $rows;
    }
}
