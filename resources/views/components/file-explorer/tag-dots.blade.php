@props([
    'tags' => [],
])

{{-- What a tag looks like from across the listing.
     Colour only, no names: an item can carry several tags and the label under a
     grid icon is already the narrowest thing on the page. The title attribute is
     what says which ones, and the inspector is where they are read and edited. --}}
@if ($tags !== [])
    <span class="fe-tag-dots" title="{{ implode(', ', array_column($tags, 'name')) }}">
        @foreach (array_slice($tags, 0, 4) as $tag)
            <span class="fe-tag__dot {{ $tag['color'] ? 'fe-tag__dot--'.$tag['color'] : '' }}"></span>
        @endforeach
    </span>
@endif
