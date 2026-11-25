<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\CommercialCatalog;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;

class CommercialController extends Controller
{
    public function index(Request $request, NetworkSettings $settings)
    {
        $state = $settings->state();
        $catalog = CommercialCatalog::fromSettings($settings->revision($state->draft_id ?? $state->published_id));
        $published = CommercialCatalog::fromSettings($settings->revision($state->published_id));
        $filters = $request->validate(['business' => 'nullable|integer|min:1']);
        $businesses = Business::where('status', 'active')->orderBy('name')->orderBy('id')->limit(100)->get(['id', 'name', 'description']);
        $preview = $businesses->firstWhere('id', (int) ($filters['business'] ?? 0));

        return view('admin.commercial', compact('state', 'catalog', 'published', 'businesses', 'preview'));
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
