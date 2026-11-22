<?php

namespace App\Support;

use App\Models\CommunityReply;
use App\Models\JobPosting;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ModerationDecision
{
    public const VERSION_RULES = ['bail', 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];

    public static function version(string $type, Model|stdClass $item, bool $lock = false): string
    {
        if ($item instanceof Listing) {
            return ListingPhotoOrder::version($item);
        }
        if ($item instanceof JobPosting) {
            return $item->reviewVersion();
        }

        $attributes = $item instanceof Model ? $item->getRawOriginal() : (array) $item;
        ksort($attributes);
        // Include the last decision so same-second pin/unpin cannot restore an earlier review token.
        $decision = DB::table('moderation_decisions')->where('type', $type)->where('resource_id', $item->id)
            ->latest('id')->when($lock, fn ($query) => $query->lockForUpdate())->value('id');

        $version = [$type, $attributes, $decision];
        if ($item instanceof CommunityReply) {
            $thread = $item->thread;
            $context = array_intersect_key($thread->getRawOriginal(), array_flip(['id', 'title', 'body', 'category', 'status', 'location_id', 'locked']));
            ksort($context);
            $context['decision'] = DB::table('moderation_decisions')->where('type', 'thread')->where('resource_id', $thread->id)
                ->latest('id')->when($lock, fn ($query) => $query->lockForUpdate())->value('id');

            $version[] = $context;
        }

        return hash('sha256', json_encode($version, JSON_THROW_ON_ERROR));
    }

    public static function requireCurrent(string $type, Model|stdClass $item, string $version): void
    {
        abort_unless(hash_equals(self::version($type, $item, lock: true), $version), 409, 'This submission changed. Reload it before reviewing.');
    }

    /** Call in the same transaction as the locked source transition and notification. */
    public static function record(string $type, int $id, string $action, int $audit, array $data, ?int $noticeSource = null): void
    {
        DB::table('moderation_decisions')->insert([
            'type' => $type, 'resource_id' => $id, 'action' => $action, 'audit_id' => $audit,
            'reason' => $data['reason'] ?? null, 'notes' => $data['notes'] ?? null,
            'notice_source_id' => $noticeSource, 'created_at' => now(),
        ]);
    }
}
