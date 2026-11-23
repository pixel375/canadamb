<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\Audit;
use App\Support\BusinessClaims;
use App\Support\BusinessDirectory;
use App\Support\ModerationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessClaimController extends Controller
{
    public function show(Request $request, int $claim, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        $item = BusinessClaims::forApplicant($site, $request->user()->id)->where('id', $claim)->firstOrFail();
        $business = Business::findOrFail($item->business_id);
        $pending = $item->status === 'pending';
        $canRevise = $pending && $directory->enabled($site) && $business->status === 'active'
            && ! $business->members()->wherePivot('role', 'owner')->exists();
        $reason = $item->status === 'rejected' ? DB::table('moderation_decisions')->where(['type' => 'claim', 'resource_id' => $claim, 'action' => 'rejected'])
            ->latest('id')->value('reason') : null;

        return response()->view('business.claim', [
            'item' => $item, 'business' => $business, 'canRevise' => $canRevise, 'reason' => $reason,
            'version' => $pending ? ModerationDecision::version('claim', $item) : null,
            'evidence' => BusinessClaims::read($item->evidence),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function update(Request $request, int $claim, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate(BusinessClaims::EVIDENCE_RULES + ['version' => ModerationDecision::VERSION_RULES]);
        $this->transition($request, $claim, $data['version'], $data);

        return redirect("/account/business-claims/$claim")->with('status', 'Your claim evidence was updated.');
    }

    public function withdraw(Request $request, int $claim)
    {
        $data = $request->validate(['version' => ModerationDecision::VERSION_RULES]);
        $this->transition($request, $claim, $data['version']);

        return redirect("/account/business-claims/$claim")->with('status', 'Your claim was withdrawn.');
    }

    private function transition(Request $request, int $id, string $version, ?array $revision = null): void
    {
        $site = $request->attributes->get('site');
        $actor = $request->user()->id;
        DB::transaction(function () use ($site, $actor, $id, $version, $revision): void {
            $scope = BusinessClaims::forApplicant($site, $actor)->where('id', $id);
            $initial = (clone $scope)->firstOrFail();
            $business = Business::lockForUpdate()->findOrFail($initial->business_id);
            $item = $scope->lockForUpdate()->firstOrFail();
            ModerationDecision::requireCurrent('claim', $item, $version);
            abort_unless($item->status === 'pending', 409);
            $context = ['claim' => $id, 'business' => $item->business_id];
            if ($revision === null) {
                $changes = ['status' => 'withdrawn'];
                $action = 'withdrawn';
            } else {
                abort_unless($business->status === 'active' && ! $business->members()->wherePivot('role', 'owner')->exists(), 409);
                $previous = BusinessClaims::read($item->evidence);
                if ($item->contact_email === $revision['contact_email'] && $previous === $revision['evidence']) {
                    return;
                }
                $changes = ['contact_email' => $revision['contact_email'], 'evidence' => BusinessClaims::seal($revision['evidence'])];
                $context['previous_evidence'] = BusinessClaims::seal(json_encode([
                    'contact_email' => $item->contact_email, 'evidence' => $previous,
                ], JSON_THROW_ON_ERROR));
                $action = 'revised';
            }
            DB::table('business_claims')->where('id', $id)->update($changes + ['updated_at' => now()]);
            Audit::record('business.claim-'.$action, $actor, $site->id, $context);
        }, attempts: 3);
    }
}
