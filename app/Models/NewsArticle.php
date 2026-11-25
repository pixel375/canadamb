<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_id', 'location_id', 'external_key', 'source_url', 'source_hash', 'input_hash', 'source_date', 'title', 'body', 'category', 'status', 'sensitive', 'verification', 'corroboration_url', 'editor_id', 'published_at'])]
class NewsArticle extends Model
{
    protected function casts(): array
    {
        return ['source_date' => 'date', 'sensitive' => 'boolean', 'published_at' => 'datetime'];
    }

    public function reviewVersion(): string
    {
        return hash('sha256', json_encode($this->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
