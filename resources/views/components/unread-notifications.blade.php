@props(['count'])
@if($count > 0)<span class="notification-badge" aria-label="{{ $count }} unread notifications">{{ $count > 99 ? '99+' : $count }}</span>@endif
