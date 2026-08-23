<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ open: false }"
        class="space-y-2"
    >
        <x-filament::button type="button" color="gray" @click="open = true">
            @svg('heroicon-o-folder-open', 'h-4 w-4')
            <span>{{ __('filament-file-explorer::file-explorer.browse_files') }}</span>
        </x-filament::button>

        <x-filament::modal id="file-explorer-picker-{{ $getId() }}" width="7xl" :visible="false" x-show="open" @close-modal.window="open = false">
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
    </div>
</x-dynamic-component>
