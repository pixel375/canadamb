<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['author_id', 'location_id', 'title', 'body', 'category', 'organizer', 'venue', 'starts_at', 'ends_at', 'event_url', 'date_only'])]
class CommunityEvent extends Model
{
    protected $attributes = ['status' => 'pending'];

    public function contentVersion(): string
    {
        return hash('sha256', json_encode([$this->title, $this->body, $this->category, $this->organizer,
            $this->venue, $this->event_url, $this->location_id, $this->starts_at, $this->ends_at,
            $this->status, $this->isDateOnly(), $this->updated_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function isDateOnly(): bool
    {
        // Persisted semantics survive a feature switch being turned off.
        return (bool) $this->date_only;
    }

    public function startInput(): string
    {
        return $this->localStart()->format($this->isDateOnly() ? 'Y-m-d' : 'Y-m-d\\TH:i');
    }

    public function endInput(): ?string
    {
        return $this->isDateOnly() ? $this->localEnd()?->subDay()->toDateString() : $this->localEnd()?->format('Y-m-d\\TH:i');
    }

    public function timeLabel(): string
    {
        return $this->isDateOnly() ? 'Time not specified' : $this->localStart()->format('g:i a');
    }

    public function startLabel(string $format = 'M j, Y · g:i a T'): string
    {
        return $this->isDateOnly() ? $this->localStart()->format('M j, Y').' · Time not specified' : $this->localStart()->format($format);
    }

    public function endLabel(string $format = 'M j, Y · g:i a T'): ?string
    {
        if (! $this->isDateOnly()) {
            return $this->localEnd()?->format($format);
        }
        $last = $this->localEnd()?->subDay();

        return $last && $last->toDateString() !== $this->localStart()->toDateString() ? $last->format('M j, Y') : null;
    }

    public function startDateValue(): string
    {
        return $this->isDateOnly() ? $this->startInput() : $this->localStart()->toAtomString();
    }

    public function endDateValue(): ?string
    {
        return $this->isDateOnly() ? $this->endInput() : $this->localEnd()?->toAtomString();
    }

    public function scheduleStarted(): bool
    {
        return $this->isDateOnly() ? $this->localStart()->toDateString() < now('America/Winnipeg')->toDateString() : $this->localStart()->isPast();
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
