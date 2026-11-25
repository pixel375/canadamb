<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class BusinessDisputes
{
    public static function enabled(): bool
    {
        return (bool) config('network.business_disputes') && Schema::hasTable('business_disputes');
    }
}
