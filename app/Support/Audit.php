<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(string $action, ?int $actor, ?int $site = null, array $context = []): int
    {
        return DB::table('audit_logs')->insertGetId([
            'action' => $action,
            'actor_id' => $actor,
            'site_id' => $site,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
