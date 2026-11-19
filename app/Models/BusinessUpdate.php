<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_location_id', 'author_id', 'title', 'body', 'type', 'status', 'published_at'])]
class BusinessUpdate extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }
}
