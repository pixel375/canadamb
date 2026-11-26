<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['business_id', 'site_id', 'author_id', 'placement', 'title', 'body', 'starts_on', 'weeks', 'estimated_cents', 'rate_revision_id'])]
class AdvertisingCampaign extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'weeks' => 'integer', 'estimated_cents' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function reviewVersion(): string
    {
        $attributes = $this->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function endsOn(): Carbon
    {
        return $this->starts_on->copy()->addWeeks($this->weeks);
    }

    public function planningPhase(?string $date = null): string
    {
        $date ??= today('America/Winnipeg')->toDateString();
        if ($this->status === 'pending' && $this->starts_on->toDateString() < $date) {
            return 'lapsed';
        }
        if ($this->status !== 'approved') {
            return $this->status;
        }
        if ($this->endsOn()->toDateString() <= $date) {
            return 'expired';
        }

        return $this->starts_on->toDateString() > $date ? 'scheduled' : 'in-window';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
