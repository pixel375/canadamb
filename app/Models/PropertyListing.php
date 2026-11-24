<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_id', 'location_id', 'title', 'description', 'transaction', 'property_type', 'seller_type', 'price_cents', 'brokerage_name', 'registration_reference',
    'bedrooms', 'bathrooms', 'floor_area', 'acreage', 'year_built', 'available_on', 'pets_allowed', 'private_address', 'legal_land_description'])]
class PropertyListing extends Model
{
    protected $attributes = ['status' => 'draft'];

    public const TYPES = ['house' => 'Detached house', 'townhouse' => 'Townhouse', 'condo' => 'Condominium',
        'apartment' => 'Apartment', 'room' => 'Room', 'duplex' => 'Duplex', 'manufactured-home' => 'Manufactured home',
        'acreage' => 'Acreage', 'farm' => 'Farm', 'vacant-land' => 'Vacant land',
        'commercial' => 'Commercial', 'industrial' => 'Industrial', 'other' => 'Other'];

    public const SELLERS = ['owner' => 'Owner', 'landlord' => 'Landlord', 'agent' => 'Agent', 'brokerage' => 'Brokerage', 'builder' => 'Builder'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'pets_allowed' => 'boolean',
            'available_on' => 'date',
            'authorization_attested_at' => 'datetime',
            'review_checklist_completed_at' => 'datetime',
            'publication_expires_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('position');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
