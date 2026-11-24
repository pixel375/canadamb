<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PropertyDraftController;
use App\Models\PropertyListing;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyReviewController extends Controller
{
    public function index()
    {
        $items = $this->reviewable()->latest('id')->paginate(20);

        return view('admin.properties', compact('items'));
    }

    public function show(int $property)
    {
        return view('properties.show', ['item' => $this->reviewable()->with('photos')->findOrFail($property), 'review' => true]);
    }

    public function media(int $property, int $photo)
    {
        return PropertyDraftController::privatePhoto($this->reviewable()->findOrFail($property), $photo);
    }

    public function update(Request $request, int $property)
    {
        abort_unless(config('network.property_drafts'), 404);
        $data = $request->validate(['action' => 'required|in:review,request-changes', 'note' => 'required|string|min:10|max:1200']);
        if ($data['action'] === 'review') {
            // A manual review is not publication authorization.
            $request->validate([
                'owner_authority_checked' => 'required|accepted',
                'seller_role_checked' => 'required|accepted',
                'property_details_checked' => 'required|accepted',
                'media_checked' => 'required|accepted',
            ]);
        }
        DB::transaction(function () use ($request, $property, $data) {
            $item = PropertyListing::whereIn('status', ['submitted', 'reviewed'])->lockForUpdate()->findOrFail($property);
            abort_if($item->status === 'reviewed' && $data['action'] !== 'request-changes', 409);
            if ($data['action'] === 'review') {
                abort_unless($item->authorization_attested_at !== null && $item->authorization_capacity !== null,
                    409, 'A recorded property authority attestation is required before review.');
                abort_if(in_array($item->seller_type, ['agent', 'brokerage'], true) && blank($item->brokerage_name),
                    409, 'Brokerage details are missing.');
            }
            $item->forceFill([
                'status' => $data['action'] === 'review' ? 'reviewed' : 'changes_requested',
                'review_note' => $data['note'],
                'review_checklist_completed_at' => $data['action'] === 'review' ? now() : null,
                'publication_expires_at' => null,
            ])->save();
            Audit::record('property-draft.'.$data['action'], $request->user()->id, context: ['property' => $item->id]);
        });

        return redirect('/admin/properties')->with('status', 'Private review decision saved.');
    }

    private function reviewable(): Builder
    {
        abort_unless(config('network.property_drafts'), 404);

        return PropertyListing::with('location')->whereIn('status', ['submitted', 'reviewed']);
    }
}
