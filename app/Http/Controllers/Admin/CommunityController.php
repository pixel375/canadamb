<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicController;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Site;
use App\Support\Audit;
use App\Support\Identity;
use App\Support\Marketplace;
use App\Support\NetworkSettings;
use App\Support\PublicationPolicy;
use App\Support\Themes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityController extends Controller
{
    public function index(Request $request, NetworkSettings $settings)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(Site::STATUSES)],
            'readiness' => 'nullable|in:confirmed,incomplete',
        ]);
        $query = Site::query();
        $term = trim($filters['q'] ?? '');
        if ($term !== '') {
            $query->where(function ($query) use ($term) {
                $query->whereLike('name', '%'.$term.'%')
                    ->orWhereHas('domain', fn ($query) => $query->whereLike('host', '%'.$term.'%'))
                    ->orWhereHas('location', fn ($query) => $query->whereLike('name', '%'.$term.'%'));
            });
        }
        if (($filters['readiness'] ?? null) === 'confirmed') {
            $query->whereHas('domain', fn ($query) => $query->whereNotNull('dns_confirmed_at')->whereNotNull('tls_confirmed_at'));
        } elseif (($filters['readiness'] ?? null) === 'incomplete') {
            $query->where(function ($query) {
                $query->whereDoesntHave('domain')->orWhereHas('domain', fn ($query) => $query->whereNull('dns_confirmed_at')->orWhereNull('tls_confirmed_at'));
            });
        }
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return view('admin.communities', [
            'sites' => $query->with(['location', 'domain'])->orderByRaw("CASE WHEN code = 'network' THEN 0 ELSE 1 END")->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(),
            'settings' => $settings, 'filters' => $filters, 'counts' => $counts,
        ]);
    }

    public function create()
    {
        return $this->form(new Site(['accent' => ['type' => 'solid', 'colors' => ['#22A06B'], 'thickness' => 4, 'direction' => 90]]));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $domain = $data['domain'];
        unset($data['domain']);
        try {
            $site = DB::transaction(function () use ($request, $data, $domain) {
                $site = Site::create($data + ['code' => (string) Str::uuid(), 'status' => 'draft']);
                $site->domain()->create(['host' => $domain]);
                Audit::record('site.created', $request->user()->id, $site->id, ['domain' => $domain]);

                return $site;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['domain' => 'That domain or location already has a community configuration.']);
        }

        return redirect('/admin/communities/'.$site->id)->with('status', 'Draft created. Preview the site and complete the readiness checklist.');
    }

    public function edit(Site $site)
    {
        return $this->form($site);
    }

    public function update(Request $request, Site $site)
    {
        $data = $this->validated($request, $site);
        $version = $request->validate(['version' => 'nullable|string|size:64'])['version'] ?? null;
        DB::transaction(function () use ($request, $site, $data, $version) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            $this->checkVersion($locked->configurationVersion(), $version);
            $data['overrides'] = array_replace($locked->overrides ?? [], $data['overrides']);
            $before = $locked->only(array_keys($data));
            $locked->fill($data);
            if (! $locked->isDirty()) {
                return;
            }
            $locked->save();
            Audit::record('site.updated', $request->user()->id, $site->id, ['before' => $before, 'after' => $data]);
        });

        return redirect('/admin/communities/'.$site->id)->with('status', 'Community configuration saved.');
    }

    public function publicationPolicy(Request $request, Site $site)
    {
        abort_if($site->isNetwork(), 403);
        $rules = ['version' => 'required|string|size:64', 'confirm_policy' => 'accepted'];
        foreach (PublicationPolicy::TYPES as $type => $label) {
            $rules['manual.'.$type] = 'required|boolean';
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $site, $data) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            $this->checkVersion($locked->configurationVersion(), $data['version']);
            $overrides = $locked->overrides ?? [];
            $before = $overrides['manual_publication'] ?? [];
            $after = array_map(fn ($value) => (bool) $value, $data['manual']);
            if ($before === $after) {
                return;
            }
            $overrides['manual_publication'] = $after;
            $locked->update(['overrides' => $overrides]);
            Audit::record('site.publication-policy', $request->user()->id, $locked->id, ['before' => $before, 'after' => $after]);
        }, attempts: 3);

        return redirect('/admin/communities/'.$site->id)->with('status', 'Community moderation restrictions saved.');
    }

    public function experience(Request $request, Site $site)
    {
        abort_if($site->isNetwork(), 403);
        $data = $request->validate([
            'version' => 'required|string|size:64',
            'preset' => ['required', Rule::in(array_merge(['inherit'], array_diff(array_keys(Themes::LABELS), ['custom'])))],
            'clock' => 'required|in:inherit,hide',
            'clock_format' => 'required|in:inherit,12,24',
            'weather' => 'required|in:inherit,hide',
        ]);
        DB::transaction(function () use ($request, $site, $data) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            $this->checkVersion($locked->configurationVersion(), $data['version']);
            $overrides = $locked->overrides ?? [];
            $before = $overrides['experience'] ?? [];
            $after = array_diff_key($data, ['version' => true]);
            if ($before === $after) {
                return;
            }
            $overrides['experience'] = $after;
            $locked->update(['overrides' => $overrides]);
            Audit::record('site.experience', $request->user()->id, $locked->id, ['before' => $before, 'after' => $after]);
        }, attempts: 3);

        return redirect('/admin/communities/'.$site->id)->with('status', 'Community experience settings saved.');
    }

    public function preview(Request $request, Site $site, PublicController $public, Marketplace $marketplace)
    {
        $request->attributes->set('site', $site->load(['domain', 'location']));

        return response()->view('home', $public->homeData($site, $marketplace) + ['preview' => true])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function readiness(Request $request, Site $site)
    {
        $data = $request->validate(['dns' => 'sometimes|accepted', 'tls' => 'sometimes|accepted', 'version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($request, $site, $data) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            $domain = $locked->domain()->lockForUpdate()->firstOrFail();
            $locked->setRelation('domain', $domain);
            $this->checkVersion($locked->readinessVersion(), $data['version'] ?? null);
            if ($locked->status === 'active' && ! $locked->isNetwork()
                && (! isset($data['dns']) || ! isset($data['tls']))) {
                throw ValidationException::withMessages(['status' => 'Move this portal out of Active before clearing readiness.']);
            }

            $domain->fill([
                'dns_confirmed_at' => isset($data['dns']) ? ($domain->dns_confirmed_at ?? now()) : null,
                'tls_confirmed_at' => isset($data['tls']) ? ($domain->tls_confirmed_at ?? now()) : null,
            ]);
            if (! $domain->isDirty()) {
                return;
            }
            $domain->save();
            Audit::record('site.readiness', $request->user()->id, $site->id, [
                'dns' => $domain->dns_confirmed_at !== null, 'tls' => $domain->tls_confirmed_at !== null, 'method' => 'manual',
            ]);
        });

        return redirect('/admin/communities/'.$site->id)->with('status', 'Operator confirmations saved.');
    }

    public function status(Request $request, Site $site)
    {
        abort_if($site->isNetwork(), 403);
        $data = $request->validate(['status' => ['required', Rule::in(Site::STATUSES)], 'version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($request, $site, $data) {
            $locked = Site::lockForUpdate()->findOrFail($site->id);
            $locked->setRelation('domain', $locked->domain()->lockForUpdate()->firstOrFail());
            $this->checkVersion($locked->readinessVersion(), $data['version'] ?? null);
            if (in_array($data['status'], ['active', 'ready'], true)
                && (! $locked->domain->dns_confirmed_at || ! $locked->domain->tls_confirmed_at)) {
                throw ValidationException::withMessages(['status' => 'Confirm DNS routing and HTTPS before setting this status.']);
            }
            $from = $locked->status;
            if ($from === $data['status']) {
                if ($from === 'active' && config('network.sso_enabled')) {
                    Identity::provision($locked);
                }

                return;
            }
            $locked->update(['status' => $data['status']]);
            if ($locked->status === 'active' && config('network.sso_enabled')) {
                Identity::provision($locked);
            }
            Audit::record('site.status', $request->user()->id, $site->id, ['from' => $from, 'to' => $locked->status]);
        });

        return redirect('/admin/communities/'.$site->id)->with('status', 'Community status updated.');
    }

    private function checkVersion(string $current, ?string $submitted): void
    {
        if ($submitted !== null && ! hash_equals($current, $submitted)) {
            throw ValidationException::withMessages(['version' => 'Community settings changed. Reload before saving.']);
        }
    }

    private function form(Site $site)
    {
        return view('admin.community-form', [
            'editing' => $site->exists, 'managed' => $site,
            'places' => $site->exists ? collect() : Location::selectable()->whereDoesntHave('site')->orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request, ?Site $site = null): array
    {
        $rules = [
            'name' => 'required|string|max:80', 'subtitle' => 'nullable|string|max:120', 'tagline' => 'nullable|string|max:120',
            'description' => 'required|string|max:500', 'contact_email' => 'nullable|email|max:120', 'seo_title' => 'nullable|string|max:120',
            'logo_url' => 'nullable|url:https|max:255',
            'accent_type' => 'required|in:solid,gradient', 'accent_colors' => 'required|string|max:100',
            'accent_thickness' => 'required|integer|between:2,8', 'accent_direction' => 'required|integer|between:0,360',
            'accent_radius' => 'nullable|integer|between:0,8', 'accent_shadow' => 'nullable|boolean',
        ];
        foreach (NetworkSettings::OPERATIONAL_MODULES as $key => $label) {
            $rules['modules.'.$key] = 'nullable|in:inherit,hide';
        }
        if (! $site) {
            if (is_string($request->input('domain'))) {
                $request->merge(['domain' => strtolower(trim($request->input('domain')))]);
            }
            $rules['domain'] = ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',
                Rule::unique(Domain::class, 'host')];
            $rules['location_id'] = ['required', 'integer', Rule::exists('locations', 'id')->where('province', 'MB')->where('selectable', true),
                Rule::unique(Site::class, 'location_id')];
        }
        $data = $request->validate($rules);
        $colors = array_map(fn ($color) => strtoupper(trim($color)), explode(',', $data['accent_colors']));
        $count = count($colors);
        if (($data['accent_type'] === 'solid' && $count !== 1) || ($data['accent_type'] === 'gradient' && ($count < 2 || $count > 8))
            || array_filter($colors, fn ($color) => ! preg_match('/^#[A-F0-9]{6}$/D', $color))) {
            throw ValidationException::withMessages(['accent_colors' => 'Enter one HEX color for a solid accent or two to eight comma-separated HEX colors for a gradient.']);
        }

        $overrides = [];
        if (! $site?->isNetwork()) {
            foreach ($data['modules'] ?? [] as $key => $value) {
                if ($value === 'hide') {
                    $overrides[$key] = false;
                }
            }
        }

        return array_diff_key($data, array_flip(['accent_type', 'accent_colors', 'accent_thickness', 'accent_direction', 'accent_radius', 'accent_shadow', 'modules'])) + [
            'accent' => ['type' => $data['accent_type'], 'colors' => $colors, 'thickness' => (int) $data['accent_thickness'],
                'direction' => (int) $data['accent_direction'], 'radius' => (int) ($data['accent_radius'] ?? 0), 'shadow' => (bool) ($data['accent_shadow'] ?? false)],
            'subtitle' => $data['subtitle'] ?? 'Manitoba Community Network',
            'tagline' => $data['tagline'] ?? 'Your '.$data['name'].'. Your community.',
            'overrides' => ['modules' => $overrides],
        ];
    }
}
