<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'site_id', 'author_id', 'placement', 'title', 'body', 'starts_on', 'weeks', 'estimated_cents', 'rate_revision_id'])]
class AdvertisingCampaign extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'weeks' => 'integer', 'estimated_cents' => 'integer', 'reviewed_at' => 'datetime'];
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
