<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'location_id', 'name', 'subtitle', 'tagline', 'description', 'status', 'seo_title', 'contact_email', 'logo_url', 'accent', 'overrides'])]
class Site extends Model
{
    public const STATUSES = ['draft', 'pending_dns', 'pending_tls', 'ready', 'active', 'maintenance', 'disabled'];

    protected function casts(): array
    {
        return ['accent' => 'array', 'overrides' => 'array'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function domain(): HasOne
    {
        return $this->hasOne(Domain::class);
    }

    public function isNetwork(): bool
    {
        return $this->code === 'network';
    }

    public function accentCss(): string
    {
        $accent = $this->accent;

        return $accent['type'] === 'gradient'
            ? 'linear-gradient('.$accent['direction'].'deg, '.implode(', ', $accent['colors']).')'
            : $accent['colors'][0];
    }
}
