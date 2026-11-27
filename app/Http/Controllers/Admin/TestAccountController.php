<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\KeywordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TestAccountController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(config('network.prelaunch'), 404);

        $filters = $request->validate(['status' => 'nullable|in:pending,verified,all', 'q' => 'nullable|string|max:100']);
        $eligible = $this->testAccounts();
        $pending = (clone $eligible)->whereNull('email_verified_at')->count();
        $verified = (clone $eligible)->whereNotNull('email_verified_at')->count();
        $accounts = $eligible->when(($filters['status'] ?? 'pending') === 'verified', fn ($query) => $query->whereNotNull('email_verified_at'))
            ->when(($filters['status'] ?? 'pending') === 'pending', fn ($query) => $query->whereNull('email_verified_at'))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['name', 'email']))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.test-accounts', compact('accounts', 'filters', 'pending', 'verified'));
    }

    public function verify(Request $request, int $account)
    {
        abort_unless(config('network.prelaunch'), 404);
        $actor = $request->user();
        abort_if($actor->id === $account, 403);

        DB::transaction(function () use ($request, $account, $actor) {
            $user = $this->testAccounts()->lockForUpdate()->findOrFail($account);
            $data = $request->validate(['version' => 'required|string|size:64']);
            if (! hash_equals($user->testVerificationVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'This test account changed. Reload and confirm the email address before verifying.']);
            }
            if ($user->hasVerifiedEmail()) {
                return;
            }
            $user->markEmailAsVerified();
            Audit::record('identity.prelaunch.verified', $actor->id, context: ['user_id' => $user->id]);
        }, attempts: 3);

        return redirect('/admin/test-accounts')->with('status', 'Test account verified.');
    }

    private function testAccounts(): Builder
    {
        return User::where('role', 'member')->whereExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs')
            ->where('action', 'identity.prelaunch.registered')->whereColumn('actor_id', 'users.id'));
    }
}
