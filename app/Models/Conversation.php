<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['listing_id', 'buyer_id', 'seller_id', 'inquiry_key', 'inquiry_business_id', 'inquiry_job_id', 'inquiry_title', 'direct_kind'])]
class Conversation extends Model
{
    public function scopeForMember(Builder $query, int $id): Builder
    {
        return $query->where(fn ($members) => $members->where('buyer_id', $id)->orWhere('seller_id', $id));
    }

    public function title(): string
    {
        return $this->listing?->title ?? $this->inquiry_title ?? 'Private conversation';
    }

    public function currentOfficialInitiator(): bool
    {
        return $this->direct_kind === 'official' && $this->buyer?->role === 'super_admin'
            && $this->buyer->hasVerifiedEmail();
    }

    public function kindLabel(): string
    {
        return match (true) {
            $this->listing_id !== null => 'Marketplace',
            $this->direct_kind === 'official' => $this->currentOfficialInitiator() ? 'Official administrator' : 'Former official conversation',
            $this->direct_kind === 'member' => 'Direct member',
            $this->inquiry_job_id !== null => 'Job inquiry',
            default => 'Business inquiry',
        };
    }

    public function archiveVersion(int $member, int $latestMessage): string
    {
        return hash('sha256', json_encode([$this->id, $member, $this->{$this->archiveMarker($member)}, $latestMessage], JSON_THROW_ON_ERROR));
    }

    public function archiveMarker(int $member): string
    {
        return $this->buyer_id === $member ? 'buyer_archived_at' : 'seller_archived_at';
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

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
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
