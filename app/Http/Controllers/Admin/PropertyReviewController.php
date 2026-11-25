<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PropertyDraftController;
use App\Models\PropertyListing;
use App\Support\Audit;
use App\Support\KeywordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyReviewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:submitted,reviewed', 'q' => 'nullable|string|max:100']);
        $matching = $this->reviewable()->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'description']));
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $matching->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString();

        return view('admin.properties', compact('items', 'filters', 'counts'));
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
            $item->forceFill([
                'status' => $data['action'] === 'review' ? 'reviewed' : 'changes_requested',
                'review_note' => $data['note'],
            ])->save();
            // A checklist records a human review, never publication permission.
            Audit::record('property-draft.'.$data['action'], $request->user()->id, context: [
                'property' => $item->id,
                'checklist_completed' => $data['action'] === 'review',
                'review_version' => 1,
            ]);
        });

        return redirect('/admin/properties')->with('status', 'Private review decision saved.');
    }

    private function reviewable(): Builder
    {
        abort_unless(config('network.property_drafts'), 404);

        return PropertyListing::with('location')->whereIn('status', ['submitted', 'reviewed']);
    }
}
