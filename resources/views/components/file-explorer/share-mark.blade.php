@props([
    'shared' => false,
    'badge' => false,
])

{{-- What a public link looks like from across the listing.
     A file that anyone with a URL can open is not something to find out by
     opening a dialog per row, so it says so where it is — the same job the tag
     dots do, and drawn as small. `badge` places it over the icon in the views
     that have one; inline everywhere else. --}}
@if ($shared)
    <span
        @class(['fe-share-mark', 'fe-share-mark--badge' => $badge])
        title="{{ __('filament-file-explorer::file-explorer.share.shared') }}"
        aria-label="{{ __('filament-file-explorer::file-explorer.share.shared') }}"
    >
        @svg('heroicon-m-link', 'fe-share-mark__icon')
    </span>
@endif
