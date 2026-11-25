<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'coverage_location_id', 'reuse', 'reuse_notes', 'enabled', 'feed_url'])]
class NewsSource extends Model
{
    public function configurationVersion(): string
    {
        return hash('sha256', json_encode([$this->id, $this->name, $this->url, $this->coverage_location_id,
            $this->reuse, $this->reuse_notes, $this->enabled, $this->feed_url], JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'next_poll_at' => 'datetime', 'poll_failures' => 'integer'];
    }
}
