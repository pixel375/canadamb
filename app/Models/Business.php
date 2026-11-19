<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'category', 'description', 'website', 'public_email', 'phone', 'hours', 'status'])]
class Business extends Model
{
    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_memberships')->withPivot('role')->withTimestamps();
    }
}
