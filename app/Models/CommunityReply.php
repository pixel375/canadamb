<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['author_id', 'body'])]
class CommunityReply extends Model
{
    protected $attributes = ['status' => 'pending'];

    public function contentVersion(): string
    {
        return hash('sha256', json_encode([$this->body, $this->status, $this->updated_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunityThread::class, 'thread_id');
    }
}
