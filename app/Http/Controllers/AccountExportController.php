<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountExportController extends Controller
{
    private const SECTIONS = [
        'listings' => ['listings', 'user_id', ['id', 'location_id', 'title', 'description', 'category', 'condition', 'price_cents', 'negotiable', 'status', 'attributes', 'created_at', 'updated_at']],
        'discussions' => ['community_threads', 'author_id', ['id', 'location_id', 'title', 'body', 'category', 'status', 'created_at', 'updated_at']],
        'replies' => ['community_replies', 'author_id', ['id', 'thread_id', 'body', 'status', 'created_at', 'updated_at']],
        'events' => ['community_events', 'author_id', ['id', 'location_id', 'title', 'body', 'category', 'organizer', 'venue', 'event_url', 'starts_at', 'ends_at', 'status', 'cancellation_note', 'created_at', 'updated_at']],
        'sent-messages' => ['messages', 'sender_id', ['id', 'conversation_id', 'body', 'created_at', 'updated_at']],
        'job-postings' => ['job_postings', 'author_id', ['id', 'business_location_id', 'title', 'description', 'employment_type', 'workplace', 'apply_url', 'closing_on', 'pay_unit', 'pay_min', 'pay_max', 'status', 'created_at', 'updated_at']],
        'business-updates' => ['business_updates', 'author_id', ['id', 'business_location_id', 'type', 'title', 'body', 'status', 'created_at', 'updated_at']],
        'business-claims' => ['business_claims', 'user_id', ['id', 'business_id', 'contact_email', 'evidence', 'status', 'created_at', 'updated_at']],
        'listing-reports' => ['listing_reports', 'reporter_id', ['id', 'listing_id', 'reason', 'resolved_at', 'created_at', 'updated_at']],
        'property-drafts' => ['property_listings', 'owner_id', ['id', 'location_id', 'title', 'description', 'transaction', 'property_type', 'seller_type', 'price_cents', 'bedrooms', 'bathrooms', 'floor_area', 'acreage', 'year_built', 'available_on', 'pets_allowed', 'private_address', 'legal_land_description', 'status', 'created_at', 'updated_at']],
    ];

    private const OPTIONAL = [
        'advertising-requests' => ['advertising_campaigns', 'author_id', ['id', 'business_id', 'site_id', 'placement', 'title', 'body', 'starts_on', 'weeks', 'estimated_cents', 'status', 'created_at', 'updated_at']],
        'marketplace-searches' => ['marketplace_searches', 'user_id', ['id', 'site_id', 'name', 'filters', 'created_at', 'updated_at']],
        'job-searches' => ['job_searches', 'user_id', ['id', 'site_id', 'name', 'filters', 'alerts', 'checked_at', 'created_at', 'updated_at']],
        'content-reports' => ['content_reports', 'reporter_id', ['id', 'site_id', 'target_type', 'target_id', 'reason', 'explanation', 'status', 'resolved_at', 'created_at', 'updated_at']],
        'business-disputes' => ['business_disputes', 'claimant_id', ['id', 'business_id', 'site_id', 'contact_email', 'explanation', 'status', 'created_at', 'updated_at']],
        'business-change-requests' => ['business_change_requests', 'requester_id', ['id', 'business_id', 'branch_id', 'location_id', 'kind', 'value', 'status', 'created_at', 'updated_at']],
        'privacy-requests' => ['account_privacy_requests', 'user_id', ['id', 'kind', 'status', 'created_at', 'updated_at']],
        'sent-business-access-requests' => ['business_access_requests', 'requester_id', ['id', 'business_id', 'kind', 'status', 'expires_at', 'created_at', 'updated_at']],
        'received-business-access-requests' => ['business_access_requests', 'recipient_id', ['id', 'business_id', 'kind', 'status', 'expires_at', 'created_at', 'updated_at']],
        'discussion-notifications' => ['community_notifications', 'user_id', ['id', 'reply_id', 'read_at', 'created_at']],
        'owner-notifications' => ['owner_notifications', 'user_id', ['id', 'kind', 'target_id', 'decision', 'read_at', 'created_at']],
        'push-device-metadata' => ['push_devices', 'user_id', ['id', 'site_id', 'created_at', 'updated_at']],
        'announcement-receipts' => ['announcement_receipts', 'user_id', ['id', 'announcement_id', 'read_at', 'created_at']],
        'authored-announcements' => ['announcements', 'author_id', ['id', 'title', 'body', 'audience', 'recipient_count', 'created_at']],
    ];

    private const BOOKMARKS = [
        'saved-listings' => ['listing_favourites', 'listing_id'],
        'saved-jobs' => ['job_posting_bookmarks', 'job_posting_id'],
        'business-memberships' => ['business_memberships', 'business_id'],
        'blocked-members' => ['user_blocks', 'blocked_id'],
        'followed-discussions' => ['community_thread_follows', 'thread_id'],
    ];

    private function sections(): array
    {
        $sections = self::SECTIONS + array_filter(self::OPTIONAL, fn ($definition) => Schema::hasTable($definition[0]));
        if (Schema::hasColumn('community_events', 'date_only')) {
            $sections['events'][2][] = 'date_only';
        }

        return $sections;
    }

    public function index(Request $request)
    {
        $counts = [];
        foreach ($this->sections() as $key => [$table, $owner]) {
            $counts[$key] = DB::table($table)->where($owner, $request->user()->id)->count();
        }

        if (Schema::hasTable('notification_preferences')) {
            $counts['notification-preferences'] = DB::table('notification_preferences')->where('user_id', $request->user()->id)->count();
        }
        foreach ($this->participantSections($request->user()->id) as $key => $query) {
            $counts[$key] = $query->count();
        }
        foreach (self::BOOKMARKS as $key => [$table]) {
            $counts[$key] = DB::table($table)->where('user_id', $request->user()->id)->count();
        }

        return view('account.export', compact('counts'));
    }

    private function participantSections(int $member): array
    {
        $conversations = DB::table('conversations')->where(fn (Builder $query) => $query->where('buyer_id', $member)->orWhere('seller_id', $member))
            ->selectRaw('id AS export_id, listing_id, created_at, updated_at')
            ->selectRaw("CASE WHEN buyer_id = ? THEN 'buyer' ELSE 'seller' END AS member_role", [$member])
            ->selectRaw('CASE WHEN buyer_id = ? THEN buyer_last_read_id ELSE seller_last_read_id END AS own_last_read_id', [$member]);
        if (Schema::hasColumns('conversations', ['inquiry_business_id', 'inquiry_job_id', 'buyer_archived_at', 'seller_archived_at'])) {
            $conversations->addSelect('inquiry_business_id', 'inquiry_job_id')
                ->selectRaw('CASE WHEN buyer_id = ? THEN buyer_archived_at ELSE seller_archived_at END AS own_archived_at', [$member]);
        }
        if (Schema::hasColumn('conversations', 'direct_kind')) {
            $conversations->addSelect('direct_kind');
        }
        $sections = ['conversation-metadata' => DB::query()->fromSub($conversations, 'export_records')];
        if (Schema::hasTable('direct_message_preferences')) {
            $preferences = DB::table('direct_message_preferences')->where('user_id', $member)
                ->selectRaw('user_id AS export_id, member_messages, official_messages, updated_at');
            $sections['direct-message-preferences'] = DB::query()->fromSub($preferences, 'export_records');
        }
        $listingPhotos = DB::table('listing_media')->join('listings', 'listings.id', '=', 'listing_media.listing_id')
            ->where('listings.user_id', $member)->selectRaw('listing_media.id AS export_id, listing_media.listing_id, listing_media.position');
        $propertyPhotos = DB::table('property_photos')->join('property_listings', 'property_listings.id', '=', 'property_photos.property_listing_id')
            ->where('property_listings.owner_id', $member)->selectRaw('property_photos.id AS export_id, property_photos.property_listing_id, property_photos.position');
        $sections['listing-photo-metadata'] = DB::query()->fromSub($listingPhotos, 'export_records');
        $sections['property-photo-metadata'] = DB::query()->fromSub($propertyPhotos, 'export_records');
        if (Schema::hasTable('broadcast_consents')) {
            $consent = DB::table('broadcast_consents')->where('user_id', $member)->selectRaw('user_id AS export_id, opted_in, updated_at');
            $sections['announcement-consent'] = DB::query()->fromSub($consent, 'export_records');
        }
        if (Schema::hasTable('business_inquiry_settings')) {
            $preferences = DB::table('business_inquiry_settings')->where('owner_id', $member)
                ->selectRaw('business_id AS export_id, business_inquiries, job_inquiries, updated_at');
            $sections['business-inquiry-preferences'] = DB::query()->fromSub($preferences, 'export_records');
        }
        if (Schema::hasTable('message_attachments')) {
            $photos = DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')
                ->where('messages.sender_id', $member)->selectRaw('message_attachments.id AS export_id, message_attachments.message_id, messages.conversation_id, message_attachments.created_at');
            $sections['sent-photo-metadata'] = DB::query()->fromSub($photos, 'export_records');
            $received = DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('messages.sender_id', '!=', $member)
                ->where(fn ($query) => $query->where('conversations.buyer_id', $member)->orWhere('conversations.seller_id', $member))
                ->selectRaw('message_attachments.id AS export_id, message_attachments.message_id, messages.conversation_id, message_attachments.created_at');
            $sections['received-photo-metadata'] = DB::query()->fromSub($received, 'export_records');
        }

        if (Schema::hasTable('message_documents')) {
            $documents = DB::table('message_documents')->join('messages', 'messages.id', '=', 'message_documents.message_id')
                ->where('messages.sender_id', $member)->selectRaw('message_documents.id AS export_id, message_documents.message_id, messages.conversation_id, message_documents.bytes, message_documents.created_at');
            $sections['sent-text-attachment-metadata'] = DB::query()->fromSub($documents, 'export_records');
            $received = DB::table('message_documents')->join('messages', 'messages.id', '=', 'message_documents.message_id')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('messages.sender_id', '!=', $member)
                ->where(fn ($query) => $query->where('conversations.buyer_id', $member)->orWhere('conversations.seller_id', $member))
                ->selectRaw('message_documents.id AS export_id, message_documents.message_id, messages.conversation_id, message_documents.bytes, message_documents.created_at');
            $sections['received-text-attachment-metadata'] = DB::query()->fromSub($received, 'export_records');
        }

        if (Schema::hasTable('event_series_occurrences')) {
            $occurrences = DB::table('event_series_occurrences')->join('community_events', 'community_events.id', '=', 'event_series_occurrences.event_id')
                ->where('community_events.author_id', $member)
                ->selectRaw('event_series_occurrences.event_id AS export_id, event_series_occurrences.series_key, event_series_occurrences.position, event_series_occurrences.original_count, event_series_occurrences.frequency');
            $sections['event-series-occurrences'] = DB::query()->fromSub($occurrences, 'export_records');
        }

        return $sections;
    }

    public function download(Request $request)
    {
        $sections = $this->sections();
        $preferenceSections = Schema::hasTable('notification_preferences') ? ['notification-preferences'] : [];
        $participantSections = $this->participantSections($request->user()->id);
        $data = $request->validate([
            'current_password' => 'required|string|max:72',
            'section' => ['required', Rule::in(['profile', ...array_keys($sections), ...array_keys(self::BOOKMARKS), ...$preferenceSections, ...array_keys($participantSections)])],
            'after_id' => 'nullable|integer|min:0',
        ]);
        $user = User::findOrFail($request->user()->id);
        if (strlen($data['current_password']) > 72 || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $section = $data['section'];
        $after = (int) ($data['after_id'] ?? 0);
        $next = null;
        if ($section === 'profile') {
            $profile = $user->only(['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at']);
            if (Schema::hasTable('user_signup_locations')) {
                $profile['signup_location_id'] = DB::table('user_signup_locations')->where('user_id', $user->id)->value('location_id');
            }
            $records = $user->id > $after ? collect([$profile]) : collect();
        } elseif ($section === 'notification-preferences') {
            $records = DB::table('notification_preferences')->where('user_id', $user->id)->where('user_id', '>', $after)
                ->get(['user_id', 'discussion_replies', 'job_summary', 'updated_at']);
        } elseif (isset($participantSections[$section])) {
            $records = $participantSections[$section]->where('export_id', '>', $after)->orderBy('export_id')->limit(501)->get();
            if ($records->count() > 500) {
                $records = $records->take(500)->values();
                $next = $records->last()->export_id;
            }
        } elseif (isset(self::BOOKMARKS[$section])) {
            [$table, $key] = self::BOOKMARKS[$section];
            $columns = $section === 'business-memberships' ? [$key, 'role', 'created_at', 'updated_at'] : [$key, 'created_at'];
            $records = DB::table($table)->where('user_id', $user->id)->where($key, '>', $after)->orderBy($key)->limit(501)->get($columns);
            if ($records->count() > 500) {
                $records = $records->take(500)->values();
                $next = $records->last()->{$key};
            }
        } else {
            [$table, $owner, $columns] = $sections[$section];
            $records = DB::table($table)->where($owner, $user->id)->where('id', '>', $after)->orderBy('id')->limit(501)->get($columns);
            if ($records->count() > 500) {
                $records = $records->take(500)->values();
                $next = $records->last()->id;
            }
        }
        $filename = 'canadamb-'.$section.($after > 0 ? '-after-'.$after : '').'.json';
        Audit::record('user.data-exported', $user->id, context: ['section' => $section, 'count' => $records->count()]);

        return response()->json([
            'format_version' => 1, 'generated_at' => now()->toIso8601String(),
            'section' => $section, 'after_id' => $after, 'next_after_id' => $next,
            'records' => $records,
        ])->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
