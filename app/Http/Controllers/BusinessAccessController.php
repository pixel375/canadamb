<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BusinessAccessController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        $id = $request->user()->id;
        $items = $this->records()->where(fn ($query) => $query->where('requester_id', $id)->orWhere('recipient_id', $id))
            ->latest('business_access_requests.id')->paginate(20);

        return view('business.access', compact('items'));
    }

    public function store(Request $request, int $business)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        $data = $request->validate(['email' => 'required|email|max:254', 'kind' => 'required|in:manager,editor,transfer', 'current_password' => 'required|string|max:72']);
        $this->confirmPassword($request, $data['current_password']);
        DB::transaction(function () use ($request, $business, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            $id = $request->user()->id;
            abort_unless($item->status === 'active' && $this->owner($item, $id), 404);
            $recipient = User::where('email', $data['email'])->whereNotNull('email_verified_at')->first();
            $eligible = $recipient && $recipient->id !== $id && ($data['kind'] === 'transfer'
                ? $item->members()->wherePivot('role', 'owner')->count() === 1
                : ! $item->members()->whereKey($recipient->id)->exists() && $item->members()->count() < 20);
            $duplicate = DB::table('business_access_requests')->where('business_id', $business)
                ->whereIn('status', ['pending', 'accepted'])->where('expires_at', '>', now())
                ->where(fn ($query) => $query->where('recipient_id', $recipient?->id)
                    ->when($data['kind'] === 'transfer', fn ($transfer) => $transfer->orWhere('kind', 'transfer')))->exists();
            if (! $eligible || $duplicate) {
                throw ValidationException::withMessages(['email' => 'Unable to create this request. Check the account, current access and outstanding requests.']);
            }
            $access = DB::table('business_access_requests')->insertGetId([
                'business_id' => $business, 'requester_id' => $id, 'recipient_id' => $recipient->id,
                'kind' => $data['kind'], 'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.access-requested', $id, context: ['request' => $access, 'business' => $business]);
        }, attempts: 3);

        return $this->done();
    }

    public function respond(Request $request, int $access)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        $data = $request->validate(['action' => 'required|in:accept,decline', 'current_password' => 'required|string|max:72']);
        $this->confirmPassword($request, $data['current_password']);
        DB::transaction(function () use ($request, $access, $data) {
            [$business, $row] = $this->lockRequest($access);
            $id = $request->user()->id;
            abort_unless((int) $row->recipient_id === $id, 404);
            abort_unless($row->status === 'pending' && $row->expires_at > now()->toDateTimeString(), 409);
            if ($data['action'] === 'decline') {
                $this->change($access, 'declined', $id, $business->id);

                return;
            }
            $this->checkParties($business, $row);
            if ($row->kind === 'transfer') {
                $this->change($access, 'accepted', $id, $business->id);

                return;
            }
            abort_unless(in_array($row->kind, ['manager', 'editor'], true) && ! $business->members()->whereKey($id)->exists() && $business->members()->count() < 20, 409);
            $business->members()->attach($id, ['role' => $row->kind]);
            $this->change($access, 'completed', $id, $business->id);
        }, attempts: 3);

        return $this->done();
    }

    public function cancel(Request $request, int $access)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        DB::transaction(function () use ($request, $access) {
            [$business, $row] = $this->lockRequest($access);
            $id = $request->user()->id;
            abort_unless((int) $row->requester_id === $id || (int) $row->recipient_id === $id, 404);
            abort_unless(in_array($row->status, ['pending', 'accepted'], true), 409);
            $this->change($access, 'cancelled', $id, $business->id);
        }, attempts: 3);

        return $this->done();
    }

    public function reviewQueue(Request $request)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        $id = $request->user()->id;
        $items = $this->records()->where('kind', 'transfer')->where('business_access_requests.status', 'accepted')
            ->where('requester_id', '!=', $id)->where('recipient_id', '!=', $id)
            ->where('expires_at', '>', now())->whereNotExists(fn ($query) => $query->selectRaw('1')->from('business_memberships')
                ->whereColumn('business_memberships.business_id', 'business_access_requests.business_id')->where('user_id', $id))
            ->latest('business_access_requests.id')->paginate(20);

        return view('admin.business-transfers', compact('items'));
    }

    public function review(Request $request, int $access)
    {
        abort_unless(BusinessAccess::enabled(), 404);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|min:10|max:1000', 'current_password' => 'required|string|max:72']);
        $this->confirmPassword($request, $data['current_password']);
        DB::transaction(function () use ($request, $access, $data) {
            [$business, $row] = $this->lockRequest($access);
            $id = $request->user()->id;
            abort_if(in_array($id, [(int) $row->requester_id, (int) $row->recipient_id], true) || $business->members()->whereKey($id)->exists(), 403);
            abort_unless($row->kind === 'transfer' && $row->status === 'accepted' && $row->expires_at > now()->toDateTimeString(), 409);
            if ($data['decision'] === 'approve') {
                $this->checkParties($business, $row);
                abort_unless($business->members()->wherePivot('role', 'owner')->count() === 1, 409);
                $business->members()->detach($row->requester_id);
                $business->members()->syncWithoutDetaching([$row->recipient_id => ['role' => 'owner']]);
                DB::table('business_access_requests')->where('business_id', $business->id)->where('id', '!=', $access)
                    ->whereIn('status', ['pending', 'accepted'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            DB::table('business_access_requests')->where('id', $access)->update(['reviewer_id' => $id, 'review_note' => $data['note']]);
            $this->change($access, $data['decision'] === 'approve' ? 'completed' : 'rejected', $id, $business->id);
        }, attempts: 3);

        return redirect('/admin/business-transfers')->with('status', 'Transfer review recorded.');
    }

    private function records()
    {
        return DB::table('business_access_requests')->join('businesses', 'businesses.id', '=', 'business_access_requests.business_id')
            ->select('business_access_requests.*', 'businesses.name as business_name');
    }

    private function lockRequest(int $access): array
    {
        $initial = DB::table('business_access_requests')->where('id', $access)->firstOrFail();
        $business = Business::lockForUpdate()->findOrFail($initial->business_id);
        $row = DB::table('business_access_requests')->where('id', $access)->lockForUpdate()->firstOrFail();

        return [$business, $row];
    }

    private function owner(Business $business, int $id): bool
    {
        return $business->members()->whereKey($id)->wherePivot('role', 'owner')->exists();
    }

    private function checkParties(Business $business, object $row): void
    {
        abort_unless($business->status === 'active' && $row->requester_id && $row->recipient_id
            && $this->owner($business, (int) $row->requester_id)
            && User::whereKey($row->recipient_id)->whereNotNull('email_verified_at')->exists(), 409);
    }

    private function confirmPassword(Request $request, string $password): void
    {
        if (strlen($password) > 72 || ! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
    }

    private function change(int $access, string $status, int $actor, int $business): void
    {
        DB::table('business_access_requests')->where('id', $access)->update(['status' => $status, 'updated_at' => now()]);
        Audit::record('business.access-'.$status, $actor, context: ['request' => $access, 'business' => $business]);
    }

    private function done()
    {
        return redirect('/account/business-access')->with('status', 'Access request updated.');
    }
}
