<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestAccountController extends Controller
{
    public function index()
    {
        abort_unless(config('network.prelaunch'), 404);

        return view('admin.test-accounts', [
            'accounts' => $this->testAccounts()->whereNull('email_verified_at')->latest('id')->paginate(25),
        ]);
    }

    public function verify(Request $request, int $account)
    {
        abort_unless(config('network.prelaunch'), 404);
        $actor = $request->user();
        abort_if($actor->id === $account, 403);

        DB::transaction(function () use ($account, $actor) {
            $user = $this->testAccounts()->lockForUpdate()->findOrFail($account);
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
