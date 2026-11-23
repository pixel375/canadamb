<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessClaims;
use App\Support\BusinessOwnership;
use App\Support\ModerationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessOwnershipReviewController extends Controller
{
    public function index()
    {
        $cases = DB::table('business_ownership_cases as ownership')
            ->join('businesses', 'businesses.id', '=', 'ownership.business_id')
            ->where(fn ($query) => $query
                ->whereIn('ownership.status', ['pending_review', 'on_hold']))
            ->select('ownership.id', 'ownership.type', 'ownership.status', 'ownership.created_at', 'businesses.name')
            ->orderBy('ownership.created_at')->orderBy('ownership.id')->paginate(20);

        return response()->view('admin.business-ownership.index', compact('cases'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function show(Request $request, int $case)
    {
        $item = DB::table('business_ownership_cases')->find($case) ?? abort(404);
        $this->independentReviewer($item, $request->user()->id);
        $business = Business::findOrFail($item->business_id);
        $actors = User::whereIn('id', array_filter([$item->initiated_by_id, $item->outgoing_owner_id, $item->proposed_owner_id]))
            ->get(['id', 'name'])->keyBy('id');
        $evidence = BusinessClaims::read($item->evidence);
        $version = ModerationDecision::version('ownership-case', $item);
        Audit::record('business.ownership-evidence-viewed', $request->user()->id, context: [
            'case' => $case, 'business' => $item->business_id,
        ]);

        return response()->view('admin.business-ownership.show', compact('item', 'business', 'actors', 'evidence', 'version'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function decide(Request $request, int $case)
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected,on_hold,dismissed,released',
            'version' => ModerationDecision::VERSION_RULES,
            'reason' => 'nullable|string|min:12|max:300',
            'proof_method' => ['nullable', Rule::in(array_keys(BusinessClaims::PROOF_METHODS))],
            'proof_reference' => 'nullable|string|min:12|max:300',
            'authority_confirmed' => 'nullable|accepted',
        ]);
        $positive = in_array($data['decision'], ['approved', 'on_hold', 'released'], true);
        if ($positive && (empty($data['proof_method']) || empty($data['proof_reference']) || empty($data['authority_confirmed']))) {
            throw ValidationException::withMessages(['proof_method' => 'An independent verification method, reference and confirmation are required.']);
        }
        if (! $positive && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Explain the rejection or dismissal before saving.']);
        }

        DB::transaction(function () use ($request, $case, $data, $positive): void {
            $initial = DB::table('business_ownership_cases')->find($case) ?? abort(404);
            $business = Business::lockForUpdate()->findOrFail($initial->business_id);
            $item = DB::table('business_ownership_cases')->where('id', $case)->lockForUpdate()->firstOrFail();
            $this->independentReviewer($item, $request->user()->id);
            ModerationDecision::requireCurrent('ownership-case', $item, $data['version']);

            if ($item->type === 'transfer') {
                abort_unless($item->status === 'pending_review'
                    && in_array($data['decision'], ['approved', 'rejected'], true), 409);
                if ($data['decision'] === 'approved') {
                    abort_unless($item->accepted_at !== null && $business->status === 'active', 409);
                    BusinessOwnership::requireUncontested($business->id);
                    abort_if(BusinessOwnership::inReview($business->id), 409,
                        'An ownership concern is awaiting independent review. Resolve it before approving a transfer.');
                    $old = User::whereKey($item->outgoing_owner_id)->lockForUpdate()->firstOrFail();
                    $next = User::whereKey($item->proposed_owner_id)->lockForUpdate()->firstOrFail();
                    abort_unless($old->hasVerifiedEmail() && $next->hasVerifiedEmail() && $old->id !== $next->id, 409);
                    abort_unless(BusinessOwnership::memberIsOwner($business->id, $old->id), 409);
                    abort_unless(! BusinessOwnership::memberIsOwner($business->id, $next->id), 409);
                    $membership = DB::table('business_memberships')->where([
                        'business_id' => $business->id, 'user_id' => $next->id,
                    ])->lockForUpdate()->first();
                    if ($membership === null) {
                        DB::table('business_memberships')->insert([
                            'business_id' => $business->id, 'user_id' => $next->id,
                            'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('business_memberships')->where([
                            'business_id' => $business->id, 'user_id' => $next->id,
                        ])->update(['role' => 'owner', 'updated_at' => now()]);
                    }
                    DB::table('business_memberships')->where([
                        'business_id' => $business->id, 'user_id' => $old->id, 'role' => 'owner',
                    ])->delete();
                    DB::table('business_locations')->where('business_id', $business->id)
                        ->where('message_contact_user_id', $old->id)->update(['message_contact_user_id' => null]);
                    DB::table('job_postings')->whereIn('business_location_id',
                        DB::table('business_locations')->select('id')->where('business_id', $business->id))
                        ->where('message_contact_user_id', $old->id)->update(['message_contact_user_id' => null]);
                    DB::table('business_team_invitations')->where([
                        'business_id' => $business->id, 'invited_by_id' => $old->id, 'status' => 'pending',
                    ])->update(['status' => 'revoked', 'responded_at' => now(), 'updated_at' => now()]);
                }
            } else {
                abort_unless($item->type === 'dispute', 409);
                if ($item->status === 'pending_review') {
                    abort_unless(in_array($data['decision'], ['on_hold', 'dismissed'], true), 409);
                } elseif ($item->status === 'on_hold') {
                    abort_unless($data['decision'] === 'released' && $item->reviewer_id !== $request->user()->id, 409);
                } else {
                    abort(409);
                }
            }

            DB::table('business_ownership_cases')->where('id', $case)->update([
                'status' => $data['decision'], 'reviewer_id' => $request->user()->id,
                'proof_method' => $positive ? $data['proof_method'] : null,
                'proof_reference' => $positive ? BusinessClaims::seal($data['proof_reference']) : null,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $audit = Audit::record('business.ownership-'.$data['decision'], $request->user()->id, context: [
                'case' => $case, 'business' => $business->id, 'type' => $item->type,
                'sealed_proof_reference' => $positive ? BusinessClaims::seal($data['proof_reference']) : null,
            ]);
            ModerationDecision::record('ownership-case', $case, $data['decision'], $audit,
                ['reason' => $data['reason'] ?? null], $audit);
        }, attempts: 3);

        return redirect('/admin/ownership-cases/'.$case)->with('status', 'Review decision saved.');
    }

    private function independentReviewer(object $case, int $reviewer): void
    {
        abort_if(in_array($reviewer, array_filter([
            $case->initiated_by_id, $case->outgoing_owner_id, $case->proposed_owner_id,
        ]), true), 403);
        abort_if(DB::table('business_memberships')->where([
            'business_id' => $case->business_id, 'user_id' => $reviewer,
        ])->exists(), 403);
    }
}
