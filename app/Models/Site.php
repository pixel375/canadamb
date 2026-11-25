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

    public function configurationVersion(): string
    {
        $attributes = $this->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function readinessVersion(): string
    {
        return hash('sha256', json_encode([$this->status, $this->domain->host,
            $this->domain->dns_confirmed_at, $this->domain->tls_confirmed_at], JSON_THROW_ON_ERROR));
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
