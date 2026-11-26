@props(['filters', 'kind', 'categories' => [], 'groups' => [], 'places' => []])
@php
    $labels = [
        'q' => 'Keywords', 'location' => 'Location', 'employer' => 'Employer', 'type' => 'Employment type',
        'workplace' => 'Workplace', 'pay_unit' => 'Pay period', 'minimum_pay' => 'Minimum pay',
        'category' => 'Category', 'group' => 'Category group', 'condition' => 'Condition',
        'min_price' => 'Minimum price', 'max_price' => 'Maximum price',
    ] + array_map(fn ($field) => $field['label'], \App\Support\MarketplaceDetailFilters::FIELDS);
@endphp
@if(count(array_intersect_key($labels, $filters)))
    <ul class="field-note" aria-label="Saved filters">
        @foreach($labels as $key => $label)
            @if(isset($filters[$key]))
                @php
                    $value = $filters[$key];
                    $display = match($key) {
                        'type' => \App\Support\JobBoard::TYPES[$value] ?? $value,
                        'workplace' => \App\Support\JobBoard::WORKPLACES[$value] ?? $value,
                        'pay_unit' => ['hour' => 'Hourly', 'year' => 'Annual'][$value] ?? $value,
                        'category' => $categories[$value] ?? $value,
                        'group' => $groups[$value]['name'] ?? $value,
                        'condition' => \App\Support\Marketplace::CONDITIONS[$value] ?? $value,
                        'location' => $places[$value] ?? $value,
                        'min_price', 'max_price', 'minimum_pay' => '$'.number_format((float) $value, 2),
                        default => $value,
                    };
                @endphp
                <li>{{ $label }}: {{ $display }}</li>
            @endif
        @endforeach
    </ul>
@else
    <p class="field-note">{{ $kind === 'jobs' ? 'All open jobs on this portal' : 'All public listings on this portal' }}</p>
@endif
