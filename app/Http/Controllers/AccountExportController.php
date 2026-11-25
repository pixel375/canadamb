<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
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
        'job-searches' => ['job_searches', 'user_id', ['id', 'site_id', 'name', 'filters', 'alerts', 'checked_at', 'created_at', 'updated_at']],
        'content-reports' => ['content_reports', 'reporter_id', ['id', 'site_id', 'target_type', 'target_id', 'reason', 'explanation', 'status', 'created_at', 'updated_at']],
        'business-disputes' => ['business_disputes', 'claimant_id', ['id', 'business_id', 'site_id', 'contact_email', 'explanation', 'status', 'created_at', 'updated_at']],
        'business-change-requests' => ['business_change_requests', 'requester_id', ['id', 'business_id', 'branch_id', 'location_id', 'kind', 'value', 'previous_value', 'status', 'created_at', 'updated_at']],
        'privacy-requests' => ['account_privacy_requests', 'user_id', ['id', 'kind', 'status', 'created_at', 'updated_at']],
    ];

    private const BOOKMARKS = [
        'saved-listings' => ['listing_favourites', 'listing_id'],
        'saved-jobs' => ['job_posting_bookmarks', 'job_posting_id'],
        'business-memberships' => ['business_memberships', 'business_id'],
        'blocked-members' => ['user_blocks', 'blocked_id'],
    ];

    private function sections(): array
    {
        return self::SECTIONS + array_filter(self::OPTIONAL, fn ($definition) => Schema::hasTable($definition[0]));
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
        foreach (self::BOOKMARKS as $key => [$table]) {
            $counts[$key] = DB::table($table)->where('user_id', $request->user()->id)->count();
        }

        return view('account.export', compact('counts'));
    }

    public function download(Request $request)
    {
        $sections = $this->sections();
        $preferenceSections = Schema::hasTable('notification_preferences') ? ['notification-preferences'] : [];
        $data = $request->validate([
            'current_password' => 'required|string|max:72',
            'section' => ['required', Rule::in(['profile', ...array_keys($sections), ...array_keys(self::BOOKMARKS), ...$preferenceSections])],
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
            $records = collect([$user->only(['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'])]);
        } elseif ($section === 'notification-preferences') {
            $records = DB::table('notification_preferences')->where('user_id', $user->id)->where('user_id', '>', $after)
                ->get(['user_id', 'discussion_replies', 'job_summary', 'updated_at']);
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
        Audit::record('user.data-exported', $user->id, context: ['section' => $section, 'count' => $records->count()]);

        return response()->json([
            'format_version' => 1, 'generated_at' => now()->toIso8601String(),
            'section' => $section, 'after_id' => $after, 'next_after_id' => $next,
            'records' => $records,
        ])->header('Content-Disposition', 'attachment; filename="canadamb-'.$section.'.json"')
            ->header('Cache-Control', 'private, no-store');
    }
}
