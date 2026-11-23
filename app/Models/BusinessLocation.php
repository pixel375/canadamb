<?php

namespace App\Models;

use App\Support\BusinessImports\BusinessCsv;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'location_id', 'address', 'address_key', 'source_id', 'external_key', 'location_status', 'imported_at', 'last_verified_at'])]
class BusinessLocation extends Model
{
    protected static function booted(): void
    {
        static::saving(function (BusinessLocation $branch): void {
            if ($branch->isDirty('external_key')) {
                $branch->import_key = $branch->external_key === null ? null : BusinessCsv::branchKey($branch->external_key);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
