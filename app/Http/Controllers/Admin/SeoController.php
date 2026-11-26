<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeoController extends Controller
{
    public function index()
    {
        $sites = Site::with(['domain', 'location'])->orderBy('name')->get();

        return view('admin.seo', compact('sites'));
    }

    public function update(Request $request, Site $site)
    {
        $data = $request->validate([
            'version' => 'required|string|size:64',
            'seo_title' => 'nullable|string|max:120',
            'seo_description' => 'nullable|string|max:160',
        ]);
        DB::transaction(function () use ($request, $site, $data) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            if (! hash_equals($locked->configurationVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'This website changed. Reload and review its current metadata.']);
            }
            $overrides = $locked->overrides ?? [];
            $before = ['seo_title' => $locked->seo_title, 'seo_description' => $overrides['seo_description'] ?? null];
            $after = ['seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null];
            if ($before === $after) {
                return;
            }
            $overrides['seo_description'] = $after['seo_description'];
            $locked->update(['seo_title' => $after['seo_title'], 'overrides' => $overrides]);
            Audit::record('site.seo.updated', $request->user()->id, $locked->id, ['before' => $before, 'after' => $after]);
        }, attempts: 3);

        return redirect('/admin/seo')->with('status', 'Website SEO metadata saved. Indexing configuration is unchanged.');
    }
}
