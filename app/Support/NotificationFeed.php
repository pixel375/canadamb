<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class NotificationFeed
{
    public const CATEGORIES = [
        'marketplace' => 'Marketplace',
        'business' => 'Businesses',
        'jobs' => 'Jobs',
        'discussions' => 'Discussions and events',
        'messages' => 'Messages',
    ];

    private const TYPES = [
        'marketplace' => ['listing'],
        'business' => ['business', 'claim', 'business-update'],
        'jobs' => ['job'],
        'discussions' => ['thread', 'reply', 'event', 'legacy'],
        'messages' => ['message'],
    ];

    public function rows(Site $site, int $user, ?string $category = null): Builder
    {
        $modules = app(NetworkSettings::class)->forSite($site)['modules'];
        $queries = [];
        foreach ($category === null ? array_keys(self::TYPES) : [$category] as $group) {
            foreach (self::TYPES[$group] as $type) {
                $module = match ($group) {
                    'marketplace', 'messages' => 'marketplace',
                    'business' => 'businesses',
                    'discussions' => $type === 'event' ? 'events' : 'community',
                    default => $group,
                };
                $queries[] = $this->source($site, $user, $type, $group, (bool) ($modules[$module] ?? false));
            }
        }
        $combined = array_shift($queries);
        foreach ($queries as $query) {
            $combined->unionAll($query);
        }

        return DB::query()->fromSub($combined, 'feed');
    }

    public function unread(Site $site, int $user): int
    {
        return $this->rows($site, $user)->where('unread', 1)->count();
    }

    public function markRead(stdClass $item, int $user): void
    {
        DB::table($this->table($item->storage))->where('user_id', $user)->where('id', $item->id)
            ->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function markAll(Site $site, int $user, ?string $category): void
    {
        foreach (['unified', 'legacy'] as $storage) {
            $rows = $this->rows($site, $user, $category)->where('storage', $storage)->whereNull('read_at');
            $last = (clone $rows)->max('id');
            if ($last === null) {
                continue;
            }
            $rows->where('id', '<=', $last)->chunkById(200, function ($items) use ($storage, $user) {
                DB::table($this->table($storage))->where('user_id', $user)->whereIn('id', $items->pluck('id'))
                    ->whereNull('read_at')->update(['read_at' => now()]);
            });
        }
    }

    public function caption(stdClass $item): string
    {
        if (! $item->relevant) {
            return 'This activity is no longer available.';
        }

        return match ($item->event) {
            'reply.followed' => 'New reply in a discussion you follow',
            'message.received' => 'New Marketplace message',
            default => match (explode('.', $item->event)[0]) {
                'listing' => 'Marketplace listing',
                'business' => 'Business profile',
                'claim' => 'Business claim',
                'business-update' => 'Business update',
                'job' => 'Job posting',
                'thread' => 'Discussion',
                'reply' => 'Discussion reply',
                'event' => 'Event',
            }.' '.explode('.', $item->event, 2)[1],
        };
    }

    public function destination(stdClass $item): ?string
    {
        if ($item->destination_key === null) {
            return null;
        }
        $key = rawurlencode((string) $item->destination_key);

        return match (explode('.', $item->event)[0]) {
            'listing' => '/marketplace/'.$key,
            'business', 'claim' => '/businesses/'.$key,
            'business-update' => '/business-updates/'.$key,
            'job' => '/jobs/'.$key,
            'thread' => '/community/'.$key,
            'reply' => '/community/replies/'.$key,
            'event' => '/events/'.$key,
            'message' => '/account/messages/'.$key,
        };
    }

    private function table(string $storage): string
    {
        return $storage === 'legacy' ? 'community_notifications' : 'notifications';
    }

    private function source(Site $site, int $user, string $type, string $category, bool $enabled): Builder
    {
        $legacy = $type === 'legacy';
        $query = DB::table($this->table($legacy ? 'legacy' : 'unified').' as notice')->where('notice.user_id', $user);
        if (! $legacy) {
            $query->whereIn('notice.event', NotificationInbox::EVENTS[$type]);
            if ($type === 'reply') {
                // A rollback may have delivered the same reply using the legacy writer.
                $query->where(fn (Builder $reply) => $reply->where('notice.event', '!=', 'reply.followed')
                    ->orWhereNotExists(fn (Builder $prior) => $prior->selectRaw('1')->from('community_notifications as prior')
                        ->whereColumn('prior.user_id', 'notice.user_id')->whereColumn('prior.reply_id', 'notice.resource_id')));
            }
            if ($site->location_id !== null) {
                $query->whereExists(fn (Builder $locations) => $locations->selectRaw('1')
                    ->from('notification_locations')->whereColumn('notification_id', 'notice.id')->where('location_id', $site->location_id));
            }
        }

        [$authorized, $published, $destination, $bindings] = match ($type) {
            'listing' => $this->listing($query, $site),
            'business', 'claim', 'business-update', 'job' => $this->business($query, $site, $type),
            'thread', 'reply', 'legacy', 'event' => $this->discussion($query, $site, $type),
            'message' => $this->message($query, $site),
        };
        $event = $legacy ? "'reply.followed'" : 'notice.event';
        $social = $legacy || $type === 'message' ? '1 = 1'
            : ($type === 'reply' ? "notice.event = 'reply.followed'" : '1 = 0');
        $applicable = $enabled ? "($authorized)" : '1 = 0';
        $hasLink = "($applicable AND ($published) AND ($social OR $event LIKE '%.approved'))";
        // A legitimate negative review is useful even when the target is no longer public.
        $relevant = "($applicable AND (($published) OR (NOT ($social) AND $event NOT LIKE '%.approved')))";
        $unread = $type === 'message'
            ? 'item.id > CASE WHEN conversation.buyer_id = notice.user_id THEN conversation.buyer_last_read_id ELSE conversation.seller_last_read_id END'
            : '1 = 1';
        $title = in_array($type, ['reply', 'legacy'], true)
            ? "CASE WHEN $hasLink AND ($social) THEN thread.title ELSE NULL END" : 'NULL';

        return $query->select('notice.id', 'notice.read_at', 'notice.created_at')
            ->selectRaw('? as storage, ? as category, '.$event.' as event', [$legacy ? 'legacy' : 'unified', $category])
            ->selectRaw("CASE WHEN $social THEN 1 ELSE 0 END as priority")
            ->selectRaw("CASE WHEN $hasLink THEN $destination ELSE NULL END as destination_key", $bindings)
            ->selectRaw("$title as title", $title === 'NULL' ? [] : $bindings)
            ->selectRaw("CASE WHEN $relevant THEN 1 ELSE 0 END as relevant", $bindings)
            ->selectRaw("CASE WHEN $relevant AND notice.read_at IS NULL AND ($unread) THEN 1 ELSE 0 END as unread", $bindings);
    }

    private function listing(Builder $query, Site $site): array
    {
        $query->leftJoin('listings as item', 'item.id', '=', 'notice.resource_id');
        $this->atLocation($query, $site, 'item.id', 'item.location_id');

        return ['item.user_id = notice.user_id', "item.status = 'active' AND item.expires_at > ?", 'item.id', [now()]];
    }

    private function business(Builder $query, Site $site, string $type): array
    {
        if ($type === 'business') {
            $query->leftJoin('businesses as business', 'business.id', '=', 'notice.resource_id');
        } elseif ($type === 'claim') {
            $query->leftJoin('business_claims as item', 'item.id', '=', 'notice.resource_id')
                ->leftJoin('businesses as business', 'business.id', '=', 'item.business_id');
        } else {
            $table = $type === 'job' ? 'job_postings' : 'business_updates';
            $query->leftJoin($table.' as item', 'item.id', '=', 'notice.resource_id')
                ->leftJoin('business_locations as branch', 'branch.id', '=', 'item.business_location_id')
                ->leftJoin('businesses as business', 'business.id', '=', 'branch.business_id');
            $this->atLocation($query, $site, 'item.id', 'branch.location_id');
        }
        if ($site->location_id !== null && in_array($type, ['business', 'claim'], true)) {
            $query->where(fn (Builder $local) => $local->whereNull('business.id')
                ->orWhereExists(fn (Builder $branches) => $branches->selectRaw('1')->from('business_locations as scope_branch')
                    ->whereColumn('scope_branch.business_id', 'business.id')->where('scope_branch.location_id', $site->location_id)));
        }
        $member = 'EXISTS (SELECT 1 FROM business_memberships AS membership WHERE membership.business_id = business.id AND membership.user_id = notice.user_id)';
        $branches = 'EXISTS (SELECT 1 FROM business_locations AS public_branch WHERE public_branch.business_id = business.id)';
        $published = "business.status = 'active' AND $branches";

        return match ($type) {
            'business' => [$member, $published, 'business.slug', []],
            'claim' => ["item.user_id = notice.user_id AND (notice.event = 'claim.rejected' OR $member)", $published, 'business.slug', []],
            'business-update' => ["item.author_id = notice.user_id AND $member", "$published AND item.status = 'approved' AND item.published_at IS NOT NULL", 'item.id', []],
            'job' => ["item.author_id = notice.user_id AND $member", "$published AND item.status = 'approved' AND item.closing_on >= ?", 'item.id', [app(JobBoard::class)->closingToday()]],
        };
    }

    private function discussion(Builder $query, Site $site, string $type): array
    {
        if (in_array($type, ['thread', 'event'], true)) {
            $table = $type === 'thread' ? 'community_threads' : 'community_events';
            $query->leftJoin($table.' as item', 'item.id', '=', 'notice.resource_id');
            $this->atLocation($query, $site, 'item.id', 'item.location_id');

            return ['item.author_id = notice.user_id', "item.status = 'approved'", 'item.id', []];
        }

        $query->leftJoin('community_replies as item', 'item.id', '=', $type === 'legacy' ? 'notice.reply_id' : 'notice.resource_id')
            ->leftJoin('community_threads as thread', 'thread.id', '=', 'item.thread_id');
        $this->atLocation($query, $site, 'thread.id', 'thread.location_id');
        $authorized = $type === 'legacy' ? 'item.id IS NOT NULL'
            : "(notice.event = 'reply.followed' AND item.id IS NOT NULL) OR item.author_id = notice.user_id";

        return [$authorized, "item.status = 'approved' AND thread.status = 'approved'", 'item.id', []];
    }

    private function message(Builder $query, Site $site): array
    {
        $query->leftJoin('messages as item', 'item.id', '=', 'notice.resource_id')
            ->leftJoin('conversations as conversation', 'conversation.id', '=', 'item.conversation_id')
            ->leftJoin('listings as listing', 'listing.id', '=', 'conversation.listing_id');
        $this->atLocation($query, $site, 'listing.id', 'listing.location_id');
        $participants = '((conversation.buyer_id = notice.user_id AND conversation.seller_id = item.sender_id)
            OR (conversation.seller_id = notice.user_id AND conversation.buyer_id = item.sender_id))';
        $blocked = 'EXISTS (SELECT 1 FROM user_blocks AS block WHERE
            (block.user_id = notice.user_id AND block.blocked_id = item.sender_id)
            OR (block.user_id = item.sender_id AND block.blocked_id = notice.user_id))';
        // A blocked or non-participating viewer cannot see even a historical stub.
        $query->whereRaw("(conversation.id IS NULL OR ($participants AND NOT $blocked))");

        // Participants can continue an existing conversation after the public listing closes.
        return [$participants, 'listing.id IS NOT NULL', 'conversation.id', []];
    }

    private function atLocation(Builder $query, Site $site, string $present, string $location): void
    {
        if ($site->location_id !== null) {
            $query->where(fn (Builder $local) => $local->whereNull($present)->orWhere($location, $site->location_id));
        }
    }
}
