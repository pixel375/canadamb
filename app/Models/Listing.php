<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'location_id', 'category', 'title', 'description', 'price_cents', 'negotiable', 'condition', 'status', 'attributes'])]
class Listing extends Model
{
    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'attributes' => 'array', 'negotiable' => 'boolean', 'published_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categoryRecord(): BelongsTo
    {
        return $this->belongsTo(ListingCategory::class, 'category', 'slug');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class)->orderBy('position');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }

    public function priceLabel(): string
    {
        return $this->price_cents === 0 ? 'Free' : '$'.number_format($this->price_cents / 100, 2);
    }
}
