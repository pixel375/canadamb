<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_location_id', 'author_id', 'title', 'description', 'employment_type', 'workplace', 'apply_url', 'closing_on', 'pay_unit', 'pay_min', 'pay_max'])]
class JobPosting extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['closing_on' => 'date', 'published_at' => 'datetime', 'pay_min' => 'integer', 'pay_max' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function payLabel(): ?string
    {
        if (! $this->pay_unit) {
            return null;
        }

        $minimum = '$'.number_format($this->pay_min / 100, 2);
        $maximum = '$'.number_format($this->pay_max / 100, 2);

        return ($minimum === $maximum ? $minimum : $minimum.'–'.$maximum).' per '.$this->pay_unit;
    }

    public function reviewVersion(): string
    {
        return hash('sha256', json_encode($this->getRawOriginal(), JSON_THROW_ON_ERROR));
    }
}
