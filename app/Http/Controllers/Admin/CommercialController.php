<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Site;
use App\Support\CommercialCatalog;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommercialController extends Controller
{
    public function index(Request $request, NetworkSettings $settings)
    {
        $state = $settings->state();
        $catalog = CommercialCatalog::fromSettings($settings->revision($state->draft_id ?? $state->published_id));
        $published = CommercialCatalog::fromSettings($settings->revision($state->published_id));
        $filters = $request->validate([
            'business' => 'nullable|integer|min:1',
            'portal' => 'nullable|integer|min:1',
            'placement' => ['nullable', Rule::in(array_keys(CommercialCatalog::PLACEMENTS))],
            'weeks' => 'nullable|integer|min:1|max:12',
        ]);
        $businesses = Business::where('status', 'active')->orderBy('name')->orderBy('id')->limit(100)->get(['id', 'name', 'description']);
        $preview = $businesses->firstWhere('id', (int) ($filters['business'] ?? 0));

        $portals = Site::where('status', 'active')->with('location')->orderBy('name')->get();
        $portal = $portals->firstWhere('id', (int) ($filters['portal'] ?? 0));
        $placement = $filters['placement'] ?? 'directory-sponsored';
        $weeks = (int) ($filters['weeks'] ?? 1);
        $estimate = CommercialCatalog::estimate($catalog, $placement, $weeks);
        $module = CommercialCatalog::PLACEMENTS[$placement];
        $warnings = [];
        if (! $preview || ! $portal) {
            $warnings[] = 'Choose an approved business and an active portal to assess the placement.';
        }
        if ($preview && $portal?->location_id && ! $preview->locations()->where('location_id', $portal->location_id)->exists()) {
            $warnings[] = 'This business has no physical branch in the selected community. Local eligibility requires review.';
        }
        if ($portal && $module && ! ($settings->forSite($portal)['modules'][$module] ?? false)) {
            $warnings[] = 'The selected portal currently hides this module.';
        }

        return view('admin.commercial', compact('state', 'catalog', 'published', 'businesses', 'preview', 'portals', 'portal', 'placement', 'weeks', 'estimate', 'warnings'));
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        $rules = ['prices' => 'required|array:'.implode(',', array_keys(CommercialCatalog::PRODUCTS))];
        foreach (CommercialCatalog::PRODUCTS as $key => $product) {
            $rules['prices.'.$key] = 'present|nullable|numeric|decimal:0,2|min:0|max:100000';
        }
        $data = $request->validate($rules);
        $settings->saveDraft($request->user()->id, function (array $current) use ($data) {
            $current['commercial_catalog'] = [];
            foreach (CommercialCatalog::PRODUCTS as $key => $product) {
                $value = $data['prices'][$key];
                $current['commercial_catalog'][$key] = ['price_cents' => $value === null ? null : (int) round($value * 100)];
            }

            return $current;
        });

        return redirect('/admin/commercial')->with('status', 'Commercial rate-card draft saved. No advertising or billing was activated.');
    }
}
