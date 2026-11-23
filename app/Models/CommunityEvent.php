<?php

namespace App\Models;

use App\Support\Community;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['author_id', 'location_id', 'title', 'body', 'category', 'organizer', 'venue', 'starts_at', 'ends_at', 'event_url'])]
class CommunityEvent extends Model
{
    protected $attributes = ['status' => 'pending'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunityThread::class);
    }

    public function publicPath(): string
    {
        return $this->thread_id === null ? '/events/'.$this->id : Community::DISCUSSIONS_PATH.'/'.$this->thread_id;
    }

    public function structuredData(string $canonical): array
    {
        return [
            '@context' => 'https://schema.org', '@type' => 'Event', 'name' => $this->title,
            'startDate' => $this->localStart()->toAtomString(), 'endDate' => $this->localEnd()?->toAtomString(),
            'description' => $this->body, 'url' => $canonical, 'eventStatus' => 'https://schema.org/EventScheduled',
            'location' => ['@type' => 'Place', 'name' => $this->venue, 'address' => [
                '@type' => 'PostalAddress', 'addressLocality' => $this->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA',
            ]],
            'organizer' => ['@type' => 'Organization', 'name' => $this->organizer],
        ];
    }

    public function localStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->starts_at, 'UTC')->setTimezone('America/Winnipeg');
    }

    public function localEnd(): ?CarbonImmutable
    {
        return $this->ends_at ? CarbonImmutable::parse($this->ends_at, 'UTC')->setTimezone('America/Winnipeg') : null;
    }
}
