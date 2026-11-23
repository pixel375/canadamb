<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Location;
use App\Support\Audit;
use App\Support\BusinessClaims;
use App\Support\BusinessDirectory;
use App\Support\BusinessOwnership;
use App\Support\ModerationDecision;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessBranchReviewController extends Controller
{
    public function index()
    {
        $requests = DB::table('business_branch_requests as change')
            ->join('businesses as business', 'business.id', '=', 'change.business_id')
            ->where('change.status', 'pending')
            ->orderBy('change.created_at')->orderBy('change.id')
            ->paginate(20, ['change.id', 'change.action', 'change.created_at', 'business.name']);

        return response()->view('admin.business-branches.index', compact('requests'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function show(Request $request, int $change)
    {
        $item = DB::table('business_branch_requests')->find($change) ?? abort(404);
        $this->independent($item, $request->user()->id);
        $business = Business::findOrFail($item->business_id);
        $branch = $item->business_location_id ? BusinessLocation::with('location')->find($item->business_location_id) : null;
        $location = Location::find($item->location_id);
        $evidence = BusinessClaims::read($item->evidence);
        $version = ModerationDecision::version('branch-request', $item);
        Audit::record('business.branch-evidence-viewed', $request->user()->id, context: [
            'business' => $business->id, 'request' => $change,
        ]);

        return response()->view('admin.business-branches.show', compact('item', 'business', 'branch', 'location', 'evidence', 'version'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function decide(Request $request, int $change)
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'version' => ModerationDecision::VERSION_RULES,
            'reason' => 'nullable|string|min:12|max:300',
            'proof_method' => ['nullable', Rule::in(array_keys(BusinessClaims::PROOF_METHODS))],
            'proof_reference' => 'nullable|string|min:12|max:300',
            'authority_confirmed' => 'nullable|accepted',
        ]);
        if ($data['decision'] === 'approved' && (empty($data['proof_method']) || empty($data['proof_reference'])
            || empty($data['authority_confirmed']))) {
            throw ValidationException::withMessages(['proof_method' => 'Approval requires an independent proof method, reference and confirmation.']);
        }
        if ($data['decision'] === 'rejected' && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Give a specific rejection reason.']);
        }

        try {
            DB::transaction(function () use ($request, $change, $data): void {
                $initial = DB::table('business_branch_requests')->find($change) ?? abort(404);
                $business = Business::lockForUpdate()->findOrFail($initial->business_id);
                $item = DB::table('business_branch_requests')->where('id', $change)->lockForUpdate()->firstOrFail();
                $this->independent($item, $request->user()->id);
                ModerationDecision::requireCurrent('branch-request', $item, $data['version']);
                abort_unless($item->status === 'pending' && $business->status === 'active', 409);
                if ($data['decision'] === 'approved') {
                    BusinessOwnership::requireUncontested($business->id);
                    abort_unless(BusinessOwnership::memberIsOwner($business->id, $item->requested_by_id), 409);
                    if ($item->action === 'add') {
                        abort_unless(Location::selectable()->whereKey($item->location_id)->exists(), 409);
                        $key = BusinessDirectory::addressKey($business->name, $item->proposed_address);
                        abort_if(DB::table('business_locations')->where([
                            'location_id' => $item->location_id, 'address_key' => $key,
                        ])->exists(), 409);
                        $branch = $business->locations()->create([
                            'location_id' => $item->location_id, 'address' => $item->proposed_address,
                            'address_key' => $key, 'location_status' => 'user_selected',
                            'last_verified_at' => now(),
                        ]);
                    } else {
                        $branch = $business->locations()->lockForUpdate()->findOrFail($item->business_location_id);
                        abort_unless($branch->location_id === $item->location_id
                            && $branch->address === $item->original_address
                            && $branch->operational_status === $item->original_status, 409,
                            'The branch changed after the proposal. Request a fresh review.');
                        switch ($item->action) {
                            case 'correct_address':
                                $key = BusinessDirectory::addressKey($business->name, $item->proposed_address);
                                abort_if(DB::table('business_locations')->where([
                                    'location_id' => $branch->location_id, 'address_key' => $key,
                                ])->where('id', '!=', $branch->id)->exists(), 409);
                                $branch->forceFill([
                                    'address' => $item->proposed_address, 'address_key' => $key,
                                    'last_verified_at' => now(),
                                ])->save();
                                break;
                            case 'temporarily_closed':
                            case 'closed':
                            case 'reopen':
                                $status = $item->action === 'reopen' ? 'open' : $item->action;
                                abort_if($status === $branch->operational_status, 409);
                                $branch->forceFill(['operational_status' => $status])->save();
                                if ($status !== 'open') {
                                    $branch->forceFill(['message_contact_user_id' => null])->save();
                                    DB::table('job_postings')->where('business_location_id', $branch->id)
                                        ->whereNotNull('message_contact_user_id')
                                        ->update(['message_contact_user_id' => null]);
                                }
                                break;
                            default:
                                abort(409);
                        }
                    }
                    $contextBranch = $branch->id;
                } else {
                    $contextBranch = $item->business_location_id;
                }
                DB::table('business_branch_requests')->where('id', $change)->update([
                    'status' => $data['decision'], 'reviewer_id' => $request->user()->id,
                    'proof_reference' => $data['decision'] === 'approved' ? BusinessClaims::seal($data['proof_reference']) : null,
                    'reviewed_at' => now(), 'updated_at' => now(),
                ]);
                $audit = Audit::record('business.branch-change-'.$data['decision'], $request->user()->id, context: [
                    'request' => $change, 'business' => $business->id, 'branch' => $contextBranch,
                    'action' => $item->action,
                    'sealed_proof_reference' => $data['decision'] === 'approved' ? BusinessClaims::seal($data['proof_reference']) : null,
                ]);
                ModerationDecision::record('branch-request', $change, $data['decision'], $audit,
                    ['reason' => $data['reason'] ?? null], $audit);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This physical branch already exists. Request a fresh review.');
        }

        return redirect('/admin/business-branches/'.$change)->with('status', 'Branch review saved.');
    }

    private function independent(object $item, int $reviewer): void
    {
        abort_if($item->requested_by_id === $reviewer, 403);
        abort_if(DB::table('business_memberships')->where([
            'business_id' => $item->business_id, 'user_id' => $reviewer,
        ])->exists(), 403);
    }
}
