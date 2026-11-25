<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['author_id', 'location_id', 'title', 'body', 'category', 'organizer', 'venue', 'starts_at', 'ends_at', 'event_url'])]
class CommunityEvent extends Model
{
    protected $attributes = ['status' => 'pending'];

    public function contentVersion(): string
    {
        return hash('sha256', json_encode([$this->title, $this->body, $this->category, $this->organizer,
            $this->venue, $this->event_url, $this->location_id, $this->starts_at, $this->ends_at,
            $this->status, $this->updated_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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
