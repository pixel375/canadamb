<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['bio', 'preferred_location_id', 'show_bio', 'show_city', 'show_listings', 'notify_discussion_replies', 'notify_marketplace_messages'])]
class MemberSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $attributes = [
        'show_bio' => false,
        'show_city' => false,
        'show_listings' => true,
        'notify_discussion_replies' => true,
        'notify_marketplace_messages' => true,
    ];

    protected function casts(): array
    {
        return [
            'show_bio' => 'boolean',
            'show_city' => 'boolean',
            'show_listings' => 'boolean',
            'notify_discussion_replies' => 'boolean',
            'notify_marketplace_messages' => 'boolean',
        ];
    }
}
