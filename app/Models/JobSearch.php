<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'site_id', 'name', 'filters', 'filter_key', 'alerts', 'checked_at'])]
class JobSearch extends Model
{
    public function editVersion(): string
    {
        return hash('sha256', json_encode([$this->name, $this->filter_key, $this->alerts, $this->checked_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return ['filters' => 'array', 'alerts' => 'boolean', 'checked_at' => 'datetime'];
    }
}
