<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();

        $published = $settings->revision($state->published_id);
        $draft = $settings->revision($state->draft_id ?? $state->published_id);
        $sections = [
            'Theme and appearance' => ['preset', 'palette'], 'Module availability' => ['modules', 'permitted_module_overrides'],
            'App installation and registration' => ['pwa_install_enabled', 'prelaunch_test_registration_enabled'],
            'Moderation policy' => ['publication_policy'], 'Local clock and weather' => ['local_clock_enabled', 'clock_format', 'weather_enabled', 'weather_key_encrypted'],
            'Commercial planning' => ['commercial_catalog'],
        ];
        $changes = [];
        foreach ($sections as $label => $keys) {
            foreach ($keys as $key) {
                if (($published[$key] ?? null) !== ($draft[$key] ?? null)) {
                    $changes[] = $label;
                    break;
                }
            }
        }

        return view('admin.settings', [
            'state' => $state,
            'settings' => $draft, 'changes' => $changes,
            'modules' => NetworkSettings::OPERATIONAL_MODULES,
            'revisions' => DB::table('configuration_revisions')->select(['id', 'actor_id', 'created_at'])->orderByDesc('id')->paginate(15, ['*'], 'revisions')->withQueryString(),
        ]);
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        $rules = ['configuration_version' => 'nullable|string|size:64', 'pwa_install_enabled' => 'nullable|boolean', 'prelaunch_test_registration_enabled' => 'sometimes|boolean'];
        foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
            $rules['modules.'.$key] = 'nullable|boolean';
            $rules['overrides.'.$key] = 'nullable|boolean';
        }
        $data = $request->validate($rules);
        $settings->saveDraft($request->user()->id, function (array $current) use ($data) {
            foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
                $current['modules'][$key] = (bool) ($data['modules'][$key] ?? false);
            }
            $current['pwa_install_enabled'] = (bool) ($data['pwa_install_enabled'] ?? false);
            if (isset($data['prelaunch_test_registration_enabled'])) {
                $current['prelaunch_test_registration_enabled'] = (bool) $data['prelaunch_test_registration_enabled'];
            }
            $current['permitted_module_overrides'] = array_keys(array_filter($data['overrides'] ?? []));

            return $current;
        }, $data['configuration_version'] ?? null);

        return redirect('/admin/settings')->with('status', 'Settings draft saved.');
    }

    public function discard(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['configuration_version' => 'required|string|size:64', 'confirm_discard' => 'accepted']);
        $settings->discardDraft($request->user()->id, $data['configuration_version']);

        return redirect('/admin/settings')->with('status', 'Pending network configuration discarded. Published settings were not changed.');
    }

    public function publish(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['configuration_version' => 'nullable|string|size:64']);
        $settings->publish($request->user()->id, expected: $data['configuration_version'] ?? null);

        return redirect('/admin/settings')->with('status', 'Network settings published.');
    }
}
