<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['listing_id', 'image_path', 'thumbnail_path', 'position'])]
class ListingMedia extends Model
{
    protected $table = 'listing_media';

    public $timestamps = false;

    public static function referencesPath(string $path): bool
    {
        return self::query()->toBase()->useWritePdo()->where('image_path', $path)->orWhere('thumbnail_path', $path)->exists();
    }
}
