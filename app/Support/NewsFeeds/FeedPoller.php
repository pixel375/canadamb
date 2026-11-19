<?php

namespace App\Support\NewsFeeds;

use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedPoller
{
    public function __construct(
        private readonly FeedTransport $transport,
        private readonly FeedParser $parser,
    ) {}

    public function poll(): array
    {
        $ids = $this->due()->orderBy('next_poll_at')->orderBy('id')->limit(3)->pluck('id');
        $counts = ['checked' => 0, 'failed' => 0];
        $deadline = hrtime(true) + 15_000_000_000;
        foreach ($ids as $id) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $claim = Str::uuid()->toString();
            $source = $this->claim($id, $claim);
            if (! $source) {
                continue;
            }

            $error = null;
            $candidates = [];
            try {
                $host = parse_url($source->url, PHP_URL_HOST);
                $feed = is_string($host) ? FeedUrl::normalize($source->feed_url, $host) : null;
                if ($feed === null) {
                    throw new FeedFailure('Feed must use the approved source hostname.');
                }
                $candidates = $this->parser->candidates($this->transport->fetch($feed, $deadline), $feed);
            } catch (FeedFailure $exception) {
                $error = $exception->getMessage();
            }

            if ($this->finish($source, $claim, $candidates, $error)) {
                $counts['checked']++;
                $counts['failed'] += (int) ($error !== null);
            }
        }

        return $counts;
    }

    private function due(): Builder
    {
        return NewsSource::where('enabled', true)->where('reuse', 'summary-permitted')->whereNotNull('feed_url')
            ->where(fn (Builder $query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()));
    }

    private function claim(int $id, string $claim): ?NewsSource
    {
        return DB::transaction(function () use ($id, $claim) {
            $current = $this->due()->whereKey($id)->lockForUpdate()->first();
            $current?->forceFill(['poll_claim' => $claim, 'next_poll_at' => now()->addMinutes(2)])->save();

            return $current;
        });
    }

    private function finish(NewsSource $source, string $claim, array $candidates, ?string $error): bool
    {
        return DB::transaction(function () use ($source, $claim, $candidates, $error) {
            $current = NewsSource::whereKey($source->id)->where('poll_claim', $claim)->where('enabled', true)
                ->where('reuse', 'summary-permitted')->lockForUpdate()->first();
            if (! $current || $current->feed_url !== $source->feed_url || $current->url !== $source->url) {
                return false;
            }
            $timestamp = now();
            $created = 0;
            if ($candidates !== []) {
                $existing = DB::table('news_articles')->whereIn('source_hash', array_column($candidates, 'source_hash'))
                    ->pluck('source_hash')->flip();
                $rows = [];
                foreach ($candidates as $candidate) {
                    if (! $existing->has($candidate['source_hash'])) {
                        $rows[] = $candidate + ['source_id' => $source->id, 'created_at' => $timestamp];
                    }
                }
                if ($rows !== []) {
                    $created = DB::table('news_feed_candidates')->insertOrIgnore($rows);
                }
            }
            $failures = $error === null ? 0 : min(4, $current->poll_failures + 1);
            $delay = $failures === 0 ? 60 : 15 * 2 ** ($failures - 1);
            $current->forceFill([
                'poll_claim' => null, 'next_poll_at' => $timestamp->copy()->addMinutes($delay), 'poll_failures' => $failures,
            ])->save();

            DB::table('news_feed_checks')->insert([
                'source_id' => $source->id, 'status' => $error === null ? 'success' : 'failed',
                'new_candidates' => $created, 'detail' => $error, 'created_at' => $timestamp,
            ]);

            return true;
        });
    }
}
