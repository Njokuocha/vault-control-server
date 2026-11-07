<a href="{{ route('people.show', $person) }}" wire:navigate class="case-linked-person-card">
    <span class="case-linked-person-card__portrait">
        @if (($person->main_image_path || $person->main_image_file_id) && $personMediaToken)
            <img src="{{ route('people.images.thumbnail', ['person' => $person, 'image' => 'main', 'auth' => $personMediaToken]) }}" alt="" loading="lazy" decoding="async">
        @else
            <i class="ri ri-user-3-line" aria-hidden="true"></i>
        @endif
    </span>
    <span class="case-linked-person-card__details">
        <strong>{{ $person->public_id }}</strong>
        <small>{{ $person->name }}</small>
        <em>{{ $person->flag }} · {{ $person->gender }}</em>
    </span>
    <i class="ri ri-arrow-right-up-line case-linked-person-card__arrow" aria-hidden="true"></i>
</a>
