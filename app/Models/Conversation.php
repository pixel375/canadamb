<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['listing_id', 'buyer_id', 'seller_id'])]
class Conversation extends Model
{
    public function scopeForMember(Builder $query, int $id): Builder
    {
        return $query->where(fn ($members) => $members->where('buyer_id', $id)->orWhere('seller_id', $id));
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function other(int $member): int
    {
        return $this->buyer_id === $member ? $this->seller_id : $this->buyer_id;
    }

    public function marker(int $member): string
    {
        return $this->buyer_id === $member ? 'buyer_last_read_id' : 'seller_last_read_id';
    }
}
