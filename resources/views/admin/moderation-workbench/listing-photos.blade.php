@if($item->media->isNotEmpty())
    <ul class="listing-photo-previews" aria-label="Submitted photos">
        @foreach($item->media as $photo)
            <li>
                <a class="listing-gallery-link" href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}" target="_blank" rel="noopener">
                    <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="Submitted photo {{ $loop->iteration }} (opens in a new tab)" loading="lazy">
                </a>
            </li>
        @endforeach
    </ul>
@endif
