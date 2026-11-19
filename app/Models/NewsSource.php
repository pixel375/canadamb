<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'coverage_location_id', 'reuse', 'reuse_notes', 'enabled', 'feed_url'])]
class NewsSource extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'next_poll_at' => 'datetime', 'poll_failures' => 'integer'];
    }
}
