<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['content', 'bytes', 'sha256', 'created_at'])]
class MessageDocument extends Model
{
    public $timestamps = false;

    protected $hidden = ['content', 'sha256'];
}
