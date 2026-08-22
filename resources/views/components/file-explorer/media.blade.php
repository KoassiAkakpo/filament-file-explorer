@props([
    'thumbUrl' => null,
    'media',
    'tags' => [],
    'selectedFiles',
    'selectedFolders' => [],
    'openUrl' => null,
    'previewUrl' => null,
    'renamingType' => null,
    'renamingId' => null,
])

@php
    $isRenaming = $renamingType === 'file' && (int) $renamingId === (int) $media->id;
    $label = \Koassi\FilamentFileExplorer\Support\MediaLabel::display($media);
    $isImage = str_starts_with((string) $media->mime_type, 'image/');
    // $thumbUrl comes from the component and goes through the media route.
    // $media->getUrl('thumbnail') would be a raw disk URL, readable by anyone
    // holding the link and checked by nothing.
    $thumb = $isImage ? ($thumbUrl ?: $previewUrl ?: null) : null;
    $mimeIcon = \Koassi\FilamentFileExplorer\Support\MimeIcon::forMedia($media);
@endphp

<div
    x-data
    data-id="{{ $media->id }}"
    data-fe-type="file"
    tabindex="-1"
    role="option"
    :aria-selected="sel.hasFile({{ $media->id }}) ? 'true' : 'false'"
    @unless($isRenaming)
        x-on:pointerdown="$store.feDrag.pointerDown($event, 'file', {{ $media->id }}, @js($label), $wire, sel)"
        x-on:click.stop="
            if ($store.feDrag.consumeClickSuppression()) return;
            sel.click('file', {{ $media->id }}, $event, $el);
        "
        @if($openUrl)
            x-on:dblclick.stop="openFile({{ $media->id }})"
        @endif
    @endunless
    x-on:contextmenu.stop.prevent="
        if (!sel.hasFile({{ $media->id }})) {
            sel.toggle('file', {{ $media->id }}, false);
        }
        $dispatch('fe-context', { type: 'file', id: {{ $media->id }}, name: @js($label), x: $event.clientX, y: $event.clientY });
    "
    :class="{
        'is-selected': sel.hasFile({{ $media->id }}),
        'drag-hover': sel.inMarqueeFile({{ $media->id }}),
        'fe-dragging': $store.feDrag.active && sel.hasFile({{ $media->id }}),
    }"
    @class([
        'file fe-icon-item group relative mx-0.5 flex w-[96px] cursor-default flex-col items-center px-0.5 pt-0.5 pb-0.5 text-center select-none',
        'is-renaming' => $isRenaming,
    ])
>
    <div
        class="fe-icon-well flex h-[68px] w-[76px] shrink-0 items-center justify-center rounded-xl"
        :class="{ 'fe-icon-well--selected': (sel.hasFile({{ $media->id }}) || sel.inMarqueeFile({{ $media->id }})) && !@js($isRenaming) }"
    >
        @if ($thumb)
            <img
                src="{{ $thumb }}"
                alt=""
                draggable="false"
                loading="lazy"
                decoding="async"
                class="pointer-events-none max-h-[48px] max-w-[56px] rounded-md object-contain"
            >
        @else
            <x-filament-file-explorer::file-explorer.mime-icon :icon="$mimeIcon" size="md" />
        @endif
    </div>

    <div class="fe-caption relative mt-1.5 flex w-full flex-col items-center px-0.5">
        @if ($isRenaming)
            <input
                type="text"
                data-fe-rename-input
                wire:model="renameValue"
                wire:keydown.enter.prevent="saveRename"
                wire:keydown.escape.prevent="cancelRename"
                wire:blur="saveRename"
                class="fe-rename-input fe-input w-full px-1 py-0.5 text-center text-[11px] leading-tight"
                @click.stop
                @pointerdown.stop
            >
        @else
            <span
                class="fe-label dark:text-zinc-100"
                :class="{ 'fe-label--selected': sel.hasFile({{ $media->id }}) || sel.inMarqueeFile({{ $media->id }}) }"
                title="{{ $label }}"
            >{{ $label }}</span>
            <x-filament-file-explorer::file-explorer.tag-dots :tags="$tags" />
        @endif
    </div>
</div>
