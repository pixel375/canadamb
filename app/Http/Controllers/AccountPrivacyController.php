<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\PrivacyImpact;
use App\Support\PrivacyRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountPrivacyController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(PrivacyRequests::enabled(), 404);
        $items = DB::table('account_privacy_requests')->where('user_id', $request->user()->id)->latest('id')->paginate(20, ['id', 'kind', 'status', 'created_at', 'updated_at']);

        return view('account.privacy-requests', compact('items'));
    }

    public function store(Request $request)
    {
        abort_unless(PrivacyRequests::enabled(), 404);
        $data = $request->validate(['kind' => 'required|in:deletion,data-review', 'current_password' => 'required|string|max:72', 'confirm_request' => 'accepted']);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $this->confirmPassword($user, $data['current_password']);
            if (DB::table('account_privacy_requests')->where('user_id', $user->id)->where('kind', $data['kind'])->whereIn('status', ['pending', 'in-review'])->exists()) {
                throw ValidationException::withMessages(['kind' => 'A request of this type is already open.']);
            }
            $id = DB::table('account_privacy_requests')->insertGetId([
                'user_id' => $user->id, 'kind' => $data['kind'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('user.privacy-requested', $user->id, context: ['request' => $id, 'kind' => $data['kind']]);
        }, attempts: 3);

        return redirect('/account/privacy-requests')->with('status', 'Your request was received. No account data has been deleted.');
    }

    public function withdraw(Request $request, int $privacy)
    {
        abort_unless(PrivacyRequests::enabled(), 404);
        $data = $request->validate(['current_password' => 'required|string|max:72']);
        DB::transaction(function () use ($request, $privacy, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $this->confirmPassword($user, $data['current_password']);
            $item = DB::table('account_privacy_requests')->where('user_id', $user->id)->where('id', $privacy)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($item->status, ['pending', 'in-review'], true), 409);
            DB::table('account_privacy_requests')->where('id', $privacy)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            Audit::record('user.privacy-withdrawn', $user->id, context: ['request' => $privacy]);
        }, attempts: 3);

        return redirect('/account/privacy-requests')->with('status', 'Request withdrawn.');
    }

    public function queue(Request $request)
    {
        abort_unless(PrivacyRequests::enabled(), 404);
        $filters = $request->validate([
            'status' => 'nullable|in:pending,in-review,withdrawn', 'kind' => 'nullable|in:deletion,data-review',
            'reference' => 'nullable|integer|min:1', 'inspect' => 'nullable|integer|min:1',
        ]);
        $eligible = DB::table('account_privacy_requests')->whereNotNull('user_id')->where('user_id', '!=', $request->user()->id);
        $items = (clone $eligible)->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status),
            fn ($query) => $query->whereIn('status', ['pending', 'in-review']))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('id', $reference))
            ->orderBy('id')->paginate(20)->withQueryString();
        $inspection = null;
        if (isset($filters['inspect'])) {
            $target = (clone $eligible)->whereIn('status', ['pending', 'in-review'])->where('id', $filters['inspect'])->firstOrFail();
            $inspection = ['reference' => $target->id, 'counts' => PrivacyImpact::counts((int) $target->user_id)];
            Audit::record('user.privacy-impact-inspected', $request->user()->id, context: ['request' => $target->id]);
        }

        return view('admin.privacy-requests', compact('items', 'filters', 'inspection'));
    }

    public function review(Request $request, int $privacy)
    {
        abort_unless(PrivacyRequests::enabled(), 404);
        $data = $request->validate(['note' => 'required|string|min:10|max:1000', 'current_password' => 'required|string|max:72']);
        DB::transaction(function () use ($request, $privacy, $data) {
            $this->confirmPassword($request->user(), $data['current_password']);
            $item = DB::table('account_privacy_requests')->where('id', $privacy)->lockForUpdate()->firstOrFail();
            abort_if((int) $item->user_id === $request->user()->id, 403);
            abort_unless($item->user_id && in_array($item->status, ['pending', 'in-review'], true), 409);
            DB::table('account_privacy_requests')->where('id', $privacy)->update([
                'status' => 'in-review', 'reviewer_id' => $request->user()->id, 'review_note' => $data['note'],
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('user.privacy-review-started', $request->user()->id, context: ['request' => $privacy]);
        }, attempts: 3);

        return redirect('/admin/privacy-requests')->with('status', 'Review recorded. No account data has been deleted.');
    }

    private function confirmPassword(User $user, string $password): void
    {
        if (strlen($password) > 72 || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
    }
}
