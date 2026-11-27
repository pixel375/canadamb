<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSSLAsymmetricKey;
use Throwable;

final class PushDelivery
{
    public static function enabled(): bool
    {
        return (bool) config('network.push_delivery') && ! config('network.prelaunch')
            && PushDevices::enabled() && Schema::hasTable('push_delivery_states');
    }

    public static function available(Site $site): bool
    {
        return self::enabled() && $site->status === 'active' && PushDevices::available($site) && VapidPush::signingKey() !== null;
    }

    private function current(User $user, Site $site): array
    {
        $member = $user->id;
        $messages = DB::table('messages as message')->join('conversations as conversation', 'conversation.id', '=', 'message.conversation_id')
            ->where(fn (Builder $query) => $query->where('conversation.buyer_id', $member)->orWhere('conversation.seller_id', $member))
            ->where('message.sender_id', '!=', $member)
            ->whereRaw('message.id > CASE WHEN conversation.buyer_id = ? THEN conversation.buyer_last_read_id ELSE conversation.seller_last_read_id END', [$member]);
        $discussion = app(Community::class)->enabled($site, 'community') && NotificationPreferences::forMember($member)['discussion_replies']
            ? app(AccountActivity::class)->discussionNotifications($user, $site)->whereNull('notification.read_at')->max('notification.id') : 0;
        $owner = OwnerNotifications::enabled() ? OwnerNotifications::visible($user, $site)->whereNull('alert.read_at')->max('alert.id') : 0;

        return ['message_cursor' => (int) $messages->max('message.id'), 'discussion_cursor' => (int) $discussion, 'owner_cursor' => (int) $owner];
    }

    public function run(VapidPush $transport): array
    {
        $counts = ['examined' => 0, 'accepted' => 0, 'retry' => 0, 'removed' => 0, 'baseline' => 0, 'idle' => 0];
        $key = self::enabled() ? VapidPush::signingKey() : null;
        if (! $key) {
            return $counts;
        }
        $devices = DB::table('push_devices as device')->leftJoin('push_delivery_states as state', 'state.device_id', '=', 'device.id')
            ->where(fn (Builder $query) => $query->whereNull('state.device_id')->orWhere('state.next_check_at', '<=', now()))
            ->orderByRaw('CASE WHEN state.next_check_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('state.next_check_at')->orderBy('device.id')->limit(5)->get(['device.id', 'device.user_id']);
        foreach ($devices as $candidate) {
            $counts['examined']++;
            // Lock in the same order as device registration and logout. A
            // revoked account/device cannot be used after revocation commits.
            // Never retry the whole transaction after an external send.
            $result = DB::transaction(function () use ($candidate, $transport, $key) {
                $user = User::lockForUpdate()->find($candidate->user_id);
                $device = DB::table('push_devices')->where('id', $candidate->id)->lockForUpdate()->first();
                if (! $user || ! $device) {
                    return 'idle';
                }
                if (! $user->hasVerifiedEmail()) {
                    DB::table('push_devices')->where('id', $device->id)->delete();

                    return 'removed';
                }
                $site = Site::find($device->site_id);
                $state = DB::table('push_delivery_states')->where('device_id', $device->id)->lockForUpdate()->first();
                if ($state && $state->next_check_at > now()->format('Y-m-d H:i:s')) {
                    return 'idle';
                }
                if (! $site || ! self::available($site)) {
                    $this->save($device->id, ['next_check_at' => now()->addMinutes(30), 'last_result' => 'paused']);

                    return 'idle';
                }

                return $this->deliver($device, $state, $this->current($user, $site), $transport, $key);
            });
            $counts[$result]++;
        }

        return $counts;
    }

    private function deliver(object $device, ?object $state, array $current, VapidPush $transport, OpenSSLAsymmetricKey $key): string
    {
        if (! $state || in_array($state->last_result, ['baseline', 'paused'], true)) {
            // First run baselines existing activity instead of notifying
            // about old records. Future scans deliver only newer unread IDs.
            $this->save($device->id, $current + ['next_check_at' => now()->addMinutes(5), 'attempts' => 0, 'last_result' => 'idle']);

            return 'baseline';
        }
        $next = [];
        foreach ($current as $column => $id) {
            $next[$column] = max($id, (int) $state->$column);
        }
        if (! collect($next)->contains(fn ($id, $column) => $id > (int) $state->$column)) {
            $this->save($device->id, ['next_check_at' => now()->addMinutes(5), 'attempts' => 0, 'last_result' => 'idle']);

            return 'idle';
        }
        try {
            $subscription = json_decode(Crypt::decryptString($device->subscription), true, flags: JSON_THROW_ON_ERROR);
            $endpoint = $subscription['endpoint'] ?? '';
            if (! is_string($endpoint) || ! PushDevices::endpointValid($endpoint)
                || ! hash_equals($device->endpoint_hash, hash('sha256', $endpoint))) {
                throw new \UnexpectedValueException('Invalid subscription.');
            }
        } catch (Throwable) {
            DB::table('push_devices')->where('id', $device->id)->delete();

            return 'removed';
        }
        $status = $transport->send($endpoint, $key);
        if ($status >= 200 && $status < 300) {
            $this->save($device->id, $next + ['next_check_at' => now()->addMinutes(5), 'attempts' => 0,
                'last_result' => 'accepted', 'accepted_count' => (int) $state->accepted_count + 1, 'last_accepted_at' => now()]);

            return 'accepted';
        }
        if (in_array($status, [404, 410], true)) {
            DB::table('push_devices')->where('id', $device->id)->delete();

            return 'removed';
        }
        $attempt = (int) $state->attempts + 1;
        $transient = $status === 0 || $status === 429 || $status >= 500;
        if ($transient && $attempt < 4) {
            $this->save($device->id, ['attempts' => $attempt, 'next_check_at' => now()->addMinutes([5, 15, 60][$attempt - 1]), 'last_result' => 'retry']);

            return 'retry';
        }
        // Never loop forever on a rejected request or a stale activity item.
        $this->save($device->id, $next + ['attempts' => 0, 'next_check_at' => now()->addHours(6), 'last_result' => 'failed']);

        return 'idle';
    }

    private function save(int $id, array $values): void
    {
        DB::table('push_delivery_states')->updateOrInsert(['device_id' => $id], $values + ['updated_at' => now()]);
    }
}
