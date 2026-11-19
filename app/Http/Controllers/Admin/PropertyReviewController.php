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
        DB::transaction(function () use ($request, $property, $data) {
            $item = PropertyListing::where('status', 'submitted')->lockForUpdate()->findOrFail($property);
            $item->forceFill([
                'status' => $data['action'] === 'review' ? 'reviewed' : 'changes_requested',
                'review_note' => $data['note'],
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
