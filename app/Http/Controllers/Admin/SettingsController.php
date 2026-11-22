<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApprovalPolicy;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();
        $published = $settings->revision($state->published_id);
        $draft = $state->draft_id ? $settings->revision($state->draft_id) : null;
        $previous = $state->previous_id ? $settings->revision($state->previous_id) : null;

        return view('admin.settings', [
            'state' => $state,
            'settings' => $draft ?? $published,
            'modules' => NetworkSettings::OPERATIONAL_MODULES,
            'policyTypes' => ApprovalPolicy::TYPES,
            'publishedPolicies' => ApprovalPolicy::networkModes($published),
            'draftPolicies' => ApprovalPolicy::networkModes($draft ?? $published),
            'draftAutomaticTypes' => $draft === null ? [] : ApprovalPolicy::automaticTypes($draft),
            'previousAutomaticTypes' => $previous === null ? [] : ApprovalPolicy::automaticTypes($previous),
        ]);
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        if ($request->has('approval_policies')) {
            $data = $request->validate([
                'approval_policies' => ['required', 'array:'.implode(',', array_keys(ApprovalPolicy::TYPES))],
                'approval_policies.*' => ['required', Rule::in([ApprovalPolicy::MANUAL, ApprovalPolicy::AUTO])],
            ]);
            $settings->saveDraft($request->user()->id, fn (array $current) => array_replace($current, [
                'approval_policies' => ApprovalPolicy::networkModes($data),
            ]));

            return redirect('/admin/settings')->with('status', 'Approval policy draft saved.');
        }

        $rules = ['pwa_install_enabled' => 'nullable|boolean', 'prelaunch_test_registration_enabled' => 'sometimes|boolean'];
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
        });

        return redirect('/admin/settings')->with('status', 'Settings draft saved.');
    }

    public function publish(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['revision' => 'sometimes|required|integer|min:1']);
        $settings->publish($request->user()->id, expectedRevision: isset($data['revision']) ? (int) $data['revision'] : null);

        return redirect('/admin/settings')->with('status', 'Network settings published.');
    }

    public function rollback(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $settings->publish($request->user()->id, rollback: true, expectedRevision: (int) $data['revision']);

        return redirect('/admin/settings')->with('status', 'Previous network settings restored.');
    }
}
