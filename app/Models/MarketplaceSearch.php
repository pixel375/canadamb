<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'site_id', 'name', 'filters', 'filter_key'])]
class MarketplaceSearch extends Model
{
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
