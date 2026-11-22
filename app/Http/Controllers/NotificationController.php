<?php

namespace App\Http\Controllers;

use App\Support\NotificationFeed;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use stdClass;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationFeed $feed) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        if ($request->routeIs('account.notifications.legacy')) {
            $filters['category'] = 'discussions';
        }
        $category = $filters['category'] ?? null;
        $status = $filters['status'] ?? 'all';
        $items = $this->feed->rows($request->attributes->get('site'), $request->user()->id, $category)
            ->when($status === 'unread', fn ($query) => $query->where('unread', 1))
            ->orderBy('priority')->latest('created_at')->orderBy('storage')->latest('id')->paginate(20)->appends($filters);

        return view('account.notifications', [
            'items' => $items, 'category' => $category, 'status' => $status, 'categories' => NotificationFeed::CATEGORIES,
            'feed' => $this->feed,
        ]);
    }

    public function open(Request $request, string $storage, int $notification)
    {
        $item = $this->find($request, $storage, $notification);
        $destination = $this->feed->destination($item);
        abort_unless($destination, 404);

        return redirect($destination);
    }

    public function read(Request $request, string $storage, int $notification)
    {
        $filters = $this->filters($request);
        $this->feed->markRead($this->find($request, $storage, $notification), $request->user()->id);

        return redirect()->route('account.notifications', $filters)->with('status', 'Notification marked as read.');
    }

    public function readLegacy(Request $request, int $notification)
    {
        $this->feed->markRead($this->find($request, 'legacy', $notification), $request->user()->id);

        return redirect()->route('account.notifications', ['category' => 'discussions'])->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request)
    {
        $filters = $this->filters($request);
        $this->feed->markAll($request->attributes->get('site'), $request->user()->id, $filters['category'] ?? null);

        return redirect()->route('account.notifications', $filters)->with('status', 'Notifications marked as read.');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'category' => ['nullable', Rule::in(array_keys(NotificationFeed::CATEGORIES))],
            'status' => ['nullable', Rule::in(['all', 'unread'])],
        ]);
    }

    private function find(Request $request, string $storage, int $id): stdClass
    {
        return $this->feed->rows($request->attributes->get('site'), $request->user()->id)
            ->where(['storage' => $storage, 'id' => $id])->firstOrFail();
    }
}
