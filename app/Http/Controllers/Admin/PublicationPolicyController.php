<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\NetworkSettings;
use App\Support\PublicationPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicationPolicyController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();
        $draft = $settings->revision($state->draft_id ?? $state->published_id);
        $published = $settings->revision($state->published_id);

        return view('admin.publication-policy', compact('state', 'draft', 'published'));
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        $rules = ['configuration_version' => 'required|string|size:64', 'confirm_policy' => 'accepted'];
        foreach (PublicationPolicy::TYPES as $type => $label) {
            $rules['policy.'.$type] = [$type === 'events' ? 'sometimes' : 'required', Rule::in(array_keys(PublicationPolicy::MODES))];
        }
        $data = $request->validate($rules);
        $settings->saveDraft($request->user()->id, function (array $current) use ($data) {
            $current['publication_policy'] = array_replace($current['publication_policy'] ?? [], $data['policy']);

            return $current;
        }, $data['configuration_version']);

        return redirect('/admin/publication-policy')->with('status', 'Moderation policy draft saved. Published policy is unchanged.');
    }
}
