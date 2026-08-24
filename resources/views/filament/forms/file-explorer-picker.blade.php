<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php $modalId = 'file-explorer-picker-'.$getId(); @endphp

    {{-- Opened through Filament's own modal contract, and not with an x-data of
         our own. The modal root already carries x-data="filamentModal(...)" and
         x-show="isOpen", and it opens on a windowed `open-modal` carrying its id
         — so an outer `x-show="open"` put a second x-show on the same element
         and lost: the click flipped a flag nothing was watching and the modal
         stayed shut, silently, with nothing in the console to say why.

         The `trigger` slot dispatches the event with the modal's id itself,
         which is also what stops the button and the modal drifting apart. --}}
    <x-filament::modal :id="$modalId" width="7xl">
        <x-slot name="trigger">
            <x-filament::button type="button" color="gray">
                @svg('heroicon-o-folder-open', 'h-4 w-4')
                <span>{{ __('filament-file-explorer::file-explorer.browse_files') }}</span>
            </x-filament::button>
        </x-slot>

        <x-slot name="heading">
            {{ __('filament-file-explorer::file-explorer.explorer') }}
        </x-slot>

        {{-- The modal has a heading and its own scroll, so the finder inside
             it gets less room than one on a page of its own. Overriding the
             token rather than the height keeps one owner for the rule. --}}
        <div class="fe-in-modal">
            @livewire('filament-file-explorer::file-explorer', [
                'scopeKey' => $getScopeKey(),
                'rootFolderId' => $getRootFolderId(),
            ], key('picker-'.$getId()))
        </div>
    </x-filament::modal>
</x-dynamic-component>
