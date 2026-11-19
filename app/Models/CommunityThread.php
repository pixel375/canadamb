<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['author_id', 'location_id', 'title', 'body', 'category'])]
class CommunityThread extends Model
{
    protected $attributes = ['status' => 'pending', 'pinned' => false, 'locked' => false];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'locked' => 'boolean', 'last_activity_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommunityReply::class, 'thread_id');
    }
}
