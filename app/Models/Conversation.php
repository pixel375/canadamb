<?php

namespace App\Models;

use App\Support\BusinessDirectory;
use App\Support\JobBoard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['context_type', 'listing_id', 'business_location_id', 'job_posting_id', 'location_id', 'buyer_id', 'seller_id'])]
class Conversation extends Model
{
    protected $attributes = ['context_type' => 'marketplace'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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

    public function subject(): string
    {
        return match ($this->context_type) {
            'business' => $this->branch?->business?->name ?? 'Business conversation',
            'job' => $this->job?->title ?? 'Job conversation',
            default => $this->listing?->title ?? 'Marketplace conversation',
        };
    }

    public function area(): ?Location
    {
        return $this->context_type === 'marketplace' ? $this->listing?->location : $this->location;
    }

    public function categoryLabel(): ?string
    {
        return match ($this->context_type) {
            'business' => BusinessDirectory::CATEGORIES[$this->branch?->business?->category] ?? null,
            'job' => JobBoard::TYPES[$this->job?->employment_type] ?? null,
            default => $this->listing?->categoryRecord?->name,
        };
    }

    public function peer(int $member): User
    {
        return $this->buyer_id === $member ? $this->seller : $this->buyer;
    }
}
