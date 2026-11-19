<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'type', 'province', 'source_id', 'source_key', 'parent_id', 'latitude', 'longitude', 'selectable'])]
class Location extends Model
{
    public function site(): HasOne
    {
        return $this->hasOne(Site::class);
    }

    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('province', 'MB')->where('selectable', true);
    }
}
