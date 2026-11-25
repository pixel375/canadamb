<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ContentReports;
use App\Support\MessageAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ContentReportReviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(ContentReports::enabled(), 404);
        $filters = $request->validate(['status' => 'nullable|in:pending,resolved', 'type' => ['nullable', Rule::in(array_keys(ContentReports::TYPES))], 'reference' => 'nullable|integer|min:1']);
        $reviewer = $request->user()->id;
        $items = ContentReports::reviewQueue($reviewer)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('target_type', $type))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('id', $reference))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString();
        $targets = $items->getCollection()->mapWithKeys(fn ($item) => [$item->id => ContentReports::forReview($item->target_type, $item->target_id)]);

        return view('admin.content-reports', compact('items', 'filters', 'targets'));
    }

    public function photo(Request $request, int $report)
    {
        abort_unless(ContentReports::enabled() && MessageAttachments::enabled(), 404);
        $reviewer = $request->user()->id;
        $item = DB::table('content_reports')->where('id', $report)->where('target_type', 'message-photo')
            ->where('status', 'pending')->where('reporter_id', '!=', $reviewer)->firstOrFail();
        $target = ContentReports::forReview('message-photo', $item->target_id);
        abort_unless($target && ! ContentReports::conflicts($target, $reviewer)
            && $target->sender_id !== (int) $item->reporter_id
            && in_array((int) $item->reporter_id, [(int) $target->conversation->buyer_id, (int) $target->conversation->seller_id], true), 404);
        $file = $target->attachment()->firstOrFail();
        abort_unless(preg_match('/^attachments\/[a-f0-9-]{36}\.webp$/D', $file->image_path), 404);
        $disk = Storage::disk(MessageAttachments::DISK);
        abort_unless($disk->exists($file->image_path), 404);
        Audit::record('content.report-photo-accessed', $reviewer, $item->site_id, ['report' => $report]);

        return $disk->download($file->image_path, 'reported-photo.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function resolve(Request $request, int $report)
    {
        abort_unless(ContentReports::enabled(), 404);
        $data = $request->validate(['note' => 'required|string|min:8|max:1000']);
        DB::transaction(function () use ($request, $report, $data) {
            $item = DB::table('content_reports')->where('id', $report)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'pending', 409);
            abort_if((int) $item->reporter_id === $request->user()->id, 403);
            $target = ContentReports::forReview($item->target_type, $item->target_id);
            abort_if($target && ContentReports::conflicts($target, $request->user()->id), 403);
            abort_if(ContentReports::privateType($item->target_type) && (! $target || ! ContentReports::reviewQueue($request->user()->id)->where('id', $report)->exists()), 404);
            DB::table('content_reports')->where('id', $report)->update([
                'status' => 'resolved', 'review_note' => $data['note'], 'reviewer_id' => $request->user()->id,
                'resolved_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('content.report-resolved', $request->user()->id, $item->site_id, ['report' => $report]);
        }, attempts: 3);

        return redirect('/admin/content-reports')->with('status', 'Report review recorded. Content visibility has not been changed.');
    }
}
