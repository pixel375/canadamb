<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QueueInventory
{
    public function inspect(): array
    {
        $result = ['database_queue' => false, 'ready' => null, 'delayed' => null, 'reserved' => null,
            'retryable_reservations' => null, 'oldest_ready_seconds' => null, 'failed' => null];
        if (config('queue.default') !== 'database') {
            return $result;
        }
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table');
        if (Schema::connection($connection)->hasTable($table)) {
            $now = now()->timestamp;
            $retry = max(1, (int) config('queue.connections.database.retry_after'));
            $queue = DB::connection($connection)->table($table);
            $ready = (clone $queue)->whereNull('reserved_at')->where('available_at', '<=', $now);
            $oldest = (clone $ready)->min('available_at');
            $result['database_queue'] = true;
            $result['ready'] = $ready->count();
            $result['delayed'] = (clone $queue)->whereNull('reserved_at')->where('available_at', '>', $now)->count();
            $result['reserved'] = (clone $queue)->whereNotNull('reserved_at')->count();
            $result['retryable_reservations'] = (clone $queue)->where('reserved_at', '<=', $now - $retry)->count();
            $result['oldest_ready_seconds'] = $oldest === null ? null : max(0, $now - (int) $oldest);
        }
        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $failedConnection = config('queue.failed.database');
            $failedTable = config('queue.failed.table');
            if (Schema::connection($failedConnection)->hasTable($failedTable)) {
                $result['failed'] = DB::connection($failedConnection)->table($failedTable)->count();
            }
        }

        return $result;
    }
}
