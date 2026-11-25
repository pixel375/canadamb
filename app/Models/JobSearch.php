<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'site_id', 'name', 'filters', 'filter_key', 'alerts', 'checked_at'])]
class JobSearch extends Model
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'alerts' => 'boolean', 'checked_at' => 'datetime'];
    }
}
