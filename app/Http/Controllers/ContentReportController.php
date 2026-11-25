<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\ContentReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(ContentReports::enabled(), 404);
        $site = $request->attributes->get('site');
        $items = DB::table('content_reports')->where('reporter_id', $request->user()->id)
            ->when(! $site->isNetwork(), fn ($query) => $query->where('site_id', $site->id))
            ->latest('id')->paginate(20);

        return view('account.content-reports', compact('items'));
    }

    public function create(Request $request, string $type, int $target)
    {
        $item = ContentReports::visible($request->attributes->get('site'), $type, $target);
        $reported = DB::table('content_reports')->where(['reporter_id' => $request->user()->id, 'target_type' => $type, 'target_id' => $target])->exists();

        return view('account.report-content', compact('item', 'type', 'target', 'reported'));
    }

    public function store(Request $request, string $type, int $target)
    {
        $site = $request->attributes->get('site');
        ContentReports::visible($site, $type, $target);
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(ContentReports::REASONS))], 'explanation' => 'required|string|min:10|max:1000']);
        $created = DB::transaction(function () use ($request, $site, $type, $target, $data) {
            $created = DB::table('content_reports')->insertOrIgnore($data + [
                'reporter_id' => $request->user()->id, 'site_id' => $site->id, 'target_type' => $type,
                'target_id' => $target, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created) {
                Audit::record('content.report-submitted', $request->user()->id, $site->id, ['type' => $type, 'target' => $target]);
            }

            return $created;
        });

        return redirect('/account/content-reports')->with('status', $created ? 'Your report was submitted for review.' : 'You have already reported this content.');
    }
}
