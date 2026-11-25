<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        'property-drafts' => ['property_listings', 'owner_id', ['id', 'location_id', 'title', 'description', 'transaction', 'property_type', 'seller_type', 'price_cents', 'bedrooms', 'bathrooms', 'floor_area', 'acreage', 'year_built', 'available_on', 'pets_allowed', 'private_address', 'legal_land_description', 'status', 'created_at', 'updated_at']],
    ];

    public function index(Request $request)
    {
        $counts = [];
        foreach (self::SECTIONS as $key => [$table, $owner]) {
            $counts[$key] = DB::table($table)->where($owner, $request->user()->id)->count();
        }

        return view('account.export', compact('counts'));
    }

    public function download(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:72',
            'section' => ['required', Rule::in(['profile', ...array_keys(self::SECTIONS)])],
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
        } else {
            [$table, $owner, $columns] = self::SECTIONS[$section];
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
