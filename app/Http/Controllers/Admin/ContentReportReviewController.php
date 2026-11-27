<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ContentReports;
use App\Support\MessageAttachments;
use App\Support\MessageDocuments;
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
        // The queue must not fetch or display private message bodies. Evidence
        // is available only through an explicit, audited pending-report action.
        $targets = $items->getCollection()->mapWithKeys(fn ($item) => [$item->id => ContentReports::privateType($item->target_type)
            ? null : ContentReports::forReview($item->target_type, $item->target_id)]);

        return response()->view('admin.content-reports', compact('items', 'filters', 'targets'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function evidence(Request $request, int $report)
    {
        abort_unless(ContentReports::enabled(), 404);
        $reviewer = $request->user()->id;

        return DB::transaction(function () use ($report, $reviewer) {
            $item = ContentReports::reviewQueue($reviewer)->where('id', $report)
                ->where('status', 'pending')->whereIn('target_type', ['message', 'message-document'])->lockForUpdate()->firstOrFail();
            $target = ContentReports::forReview($item->target_type, $item->target_id);
            abort_unless($target && ! ContentReports::conflicts($target, $reviewer), 404);
            if ($item->target_type === 'message-document') {
                abort_unless(MessageDocuments::enabled(), 404);
                $document = $target->document()->firstOrFail();
                abort_unless(strlen($document->content) === (int) $document->bytes && hash_equals($document->sha256, hash('sha256', $document->content)), 404);
                Audit::record('content.report-document-accessed', $reviewer, $item->site_id, ['report' => $report]);

                return response($document->content, 200, [
                    'Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="reported-document.txt"',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
                    'Content-Security-Policy' => "default-src 'none'; sandbox",
                ]);
            }
            Audit::record('content.report-message-accessed', $reviewer, $item->site_id, ['report' => $report]);

            return response()->view('admin.reported-message-evidence', ['report' => $item->id, 'body' => $target->body])
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }, attempts: 3);
    }

    public function photo(Request $request, int $report)
    {
        abort_unless(ContentReports::enabled() && MessageAttachments::enabled(), 404);
        $reviewer = $request->user()->id;

        return DB::transaction(function () use ($report, $reviewer) {
            $item = ContentReports::reviewQueue($reviewer)->where('id', $report)
                ->where('target_type', 'message-photo')->where('status', 'pending')->lockForUpdate()->firstOrFail();
            $target = ContentReports::forReview('message-photo', $item->target_id);
            abort_unless($target && ! ContentReports::conflicts($target, $reviewer), 404);
            $file = $target->attachment()->firstOrFail();
            abort_unless(preg_match('/^attachments\/[a-f0-9-]{36}\.webp$/D', $file->image_path), 404);
            $disk = Storage::disk(MessageAttachments::DISK);
            abort_unless($disk->exists($file->image_path), 404);
            $path = $disk->path($file->image_path);
            abort_unless(is_file($path), 404);
            abort_if(is_link($path), 404);
            Audit::record('content.report-photo-accessed', $reviewer, $item->site_id, ['report' => $report]);

            return $disk->download($file->image_path, 'reported-photo.webp', [
                'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
                'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }, attempts: 3);
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
