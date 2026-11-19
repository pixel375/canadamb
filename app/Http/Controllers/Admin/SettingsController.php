<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();

        return view('admin.settings', [
            'state' => $state,
            'settings' => $settings->revision($state->draft_id ?? $state->published_id),
            'modules' => NetworkSettings::OPERATIONAL_MODULES,
        ]);
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        $rules = [];
        foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
            $rules['modules.'.$key] = 'nullable|boolean';
            $rules['overrides.'.$key] = 'nullable|boolean';
        }
        $data = $request->validate($rules);
        $settings->saveDraft($request->user()->id, function (array $current) use ($data) {
            foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
                $current['modules'][$key] = (bool) ($data['modules'][$key] ?? false);
            }
            $current['permitted_module_overrides'] = array_keys(array_filter($data['overrides'] ?? []));

            return $current;
        });

        return redirect('/admin/settings')->with('status', 'Settings draft saved.');
    }

    public function publish(Request $request, NetworkSettings $settings)
    {
        $settings->publish($request->user()->id);

        return redirect('/admin/settings')->with('status', 'Network settings published.');
    }
}
