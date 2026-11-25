<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'location_id', 'address', 'address_key', 'source_id', 'external_key', 'location_status', 'imported_at', 'last_verified_at'])]
class BusinessLocation extends Model
{
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('location_status', '!=', 'closed');
    }

    public function isOpen(): bool
    {
        return $this->location_status !== 'closed';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
