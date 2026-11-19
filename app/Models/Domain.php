<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'host', 'dns_confirmed_at', 'tls_confirmed_at'])]
class Domain extends Model
{
    protected function casts(): array
    {
        return ['dns_confirmed_at' => 'datetime', 'tls_confirmed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
