<x-layouts.site title="Notifications">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Notifications</h1></div></div>
        <form class="panel form-stack" method="get" action="{{ route('account.notifications', absolute: false) }}">
            <label>Category
                <select name="category">
                    <option value="">All categories</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Show
                <select name="status">
                    <option value="all" @selected($status === 'all')>All</option>
                    <option value="unread" @selected($status === 'unread')>Unread</option>
                </select>
            </label>
            <button class="button button-outline">Apply filters</button>
        </form>
        @if($items->isNotEmpty())
            <form method="post" action="{{ route('account.notifications.read-all', ['category' => $category, 'status' => $status], false) }}" class="inline-actions">
                @csrf
                <button class="button button-outline">{{ $category ? 'Mark category as read' : 'Mark all as read' }}</button>
            </form>
            @foreach([0 => 'System & approvals', 1 => 'Social & activity'] as $priority => $heading)
                @php($section = $items->where('priority', $priority))
                @if($section->isNotEmpty())
                    <section class="panel form-stack" aria-labelledby="notification-section-{{ $priority }}">
                        <h2 id="notification-section-{{ $priority }}">{{ $heading }}</h2>
                        <ul class="notification-list">
                            @foreach($section as $item)
                                <li class="item-row">
                                    <div>
                                        <p><strong>{{ $feed->caption($item) }}</strong>@if($item->unread) <span class="notification-badge">Unread</span>@endif</p>
                                        @php($timestamp = \Illuminate\Support\Carbon::parse($item->created_at)->timezone('America/Winnipeg'))
                                        <p class="field-note">{{ $categories[$item->category] }} · <time datetime="{{ $timestamp->toAtomString() }}">{{ $timestamp->format('M j, Y, g:i a T') }}</time></p>
                                        @if($item->destination_key !== null)
                                            <a class="text-link" href="{{ route('account.notifications.open', ['storage' => $item->storage, 'notification' => $item->id], false) }}">{{ $item->title ?? 'View item' }}</a>
                                        @endif
                                    </div>
                                    @if($item->unread || (!$item->relevant && $item->read_at === null))
                                        <form method="post" action="{{ route('account.notifications.read', ['storage' => $item->storage, 'notification' => $item->id, 'category' => $category, 'status' => $status], false) }}">
                                            @csrf<button class="button button-outline">Mark as read</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        @else
            <div class="panel"><p>{{ $status === 'unread' ? 'No unread notifications in this view.' : 'No notifications in this view yet.' }}</p></div>
        @endif
        {{ $items->links() }}
    </div>
</x-layouts.site>
