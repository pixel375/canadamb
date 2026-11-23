@props(['count' => 0])
@if($count > 0)<span class="notification-badge" aria-label="{{ $count }} unread messages">{{ $count > 99 ? '99+' : $count }}</span>@endif
