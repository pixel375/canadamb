<?php

namespace App\Support;

use App\Models\Location;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class News
{
    public const CATEGORIES = ['local' => 'Local News', 'municipal' => 'Municipal Government', 'safety' => 'Public Safety',
        'roads' => 'Roads & Traffic', 'business' => 'Business & Economy', 'education' => 'Education',
        'community' => 'Community', 'events' => 'Events', 'weather' => 'Weather Alerts', 'regional' => 'Regional News'];

    public function enabled(Site $site): bool
    {
        return app(NetworkSettings::class)->forSite($site)['modules']['news'] ?? false;
    }

    public function visible(?int $location): Builder
    {
        return NewsArticle::where('status', 'published')->where('published_at', '<=', now())
            ->when($location, fn ($query, $id) => $query->where('location_id', $id))
            ->with(['source', 'location'])->latest('published_at')->orderByDesc('id');
    }

    public function canonicalSite(NewsArticle $article): Site
    {
        $article->loadMissing('location.site.domain');
        $local = $article->location?->site;

        return $local?->status === 'active' && $this->enabled($local) ? $local : NetworkUrl::main();
    }

    public function rules(): array
    {
        return [
            'source_id' => 'required|integer', 'external_key' => 'required|string|max:100',
            'source_url' => 'required|url:https|max:1024', 'source_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'location_id' => 'nullable|integer', 'title' => 'required|string|max:140', 'body' => 'required|string|min:20|max:2500',
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'sensitive' => 'nullable|boolean',
        ];
    }

    public function intake(array $data): NewsArticle
    {
        $source = NewsSource::where('enabled', true)->find($data['source_id']);
        if (! $source || $source->reuse !== 'summary-permitted') {
            throw ValidationException::withMessages(['source_id' => 'Choose an enabled source authorized for summary reuse.']);
        }
        $url = parse_url($data['source_url']);
        $host = parse_url($source->url, PHP_URL_HOST);
        if (strcasecmp($url['host'] ?? '', (string) $host) !== 0 || isset($url['user']) || isset($url['pass'])
            || (isset($url['port']) && $url['port'] !== 443)) {
            throw ValidationException::withMessages(['source_url' => 'The URL must identify the configured source hostname.']);
        }
        $location = isset($data['location_id']) ? (int) $data['location_id'] : $source->coverage_location_id;
        if (($source->coverage_location_id && $location !== $source->coverage_location_id)
            || ($location && ! Location::selectable()->whereKey($location)->exists())) {
            throw ValidationException::withMessages(['location_id' => 'The location is outside the source coverage.']);
        }
        $sourceUrl = 'https://'.strtolower($url['host']).($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
        $data['source_url'] = $sourceUrl;
        $data['source_hash'] = hash('sha256', $sourceUrl);
        $data['sensitive'] = (bool) ($data['sensitive'] ?? false) || in_array($data['category'], ['safety', 'weather'], true);
        $data['location_id'] = $location;
        ksort($data);
        $data['input_hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($source, $data) {
                $locked = NewsSource::lockForUpdate()->findOrFail($source->id);
                if (! $locked->enabled || $locked->reuse !== 'summary-permitted'
                    || ! hash_equals($source->configurationVersion(), $locked->configurationVersion())) {
                    throw ValidationException::withMessages(['source_id' => 'Source settings changed. Review the source before submitting again.']);
                }
                $existing = NewsArticle::where(['source_id' => $source->id, 'external_key' => $data['external_key']])->first()
                    ?? NewsArticle::where('source_hash', $data['source_hash'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->input_hash, $data['input_hash']), 409, 'A different submission already exists for this source item.');

                    return $existing;
                }

                return NewsArticle::create($data + ['status' => 'draft']);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'That source item already exists.');
        }
    }
}
