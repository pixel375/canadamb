@props(['count' => 0, 'label' => 'items'])
@if($count > 0)
<span class="nav-count" aria-label="{{ $count }} unread {{ $label }}" title="{{ $count }} unread {{ $label }}">{{ $count > 99 ? '99+' : $count }}</span>
@endif
