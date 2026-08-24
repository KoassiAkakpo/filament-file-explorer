<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $modalId = 'file-explorer-picker-'.$getId();
        $statePath = $getStatePath();
        $chosen = $getChosenFiles();
    @endphp

    {{-- The listener needs an Alpine scope of its own, and it deliberately does
         not sit on the modal: the modal keeps its own state and opens on its own
         event, which is the mistake this file already made once. --}}
    <div
        class="fe-picker"
        x-data="{}"
        x-on:fe-picked.window="
            if ($event.detail.token !== @js($statePath)) return;
            $wire.set(@js($statePath), @js($isMultiple()) ? $event.detail.files : ($event.detail.files[0] ?? null));
            $dispatch('close-modal', { id: @js($modalId) });
        "
    >
        {{-- Opened through Filament's own modal contract, and not with an x-data
             of our own. The modal root already carries x-data="filamentModal(…)"
             and x-show="isOpen", and it opens on a windowed `open-modal` carrying
             its id — so an outer `x-show` put a second one on the same element
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
                    'pickerToken' => $statePath,
                    'pickMultiple' => $isMultiple(),
                ], key('picker-'.$getId()))
            </div>
        </x-filament::modal>

        {{-- What the field holds, drawn from the state rather than from the
             explorer: the two are not the same thing, and a chosen file may
             live in a folder the explorer is not showing. --}}
        @if ($chosen !== [])
            <ul class="fe-picker__list">
                @foreach ($chosen as $file)
                    <li class="fe-picker__item">
                        @svg('heroicon-o-document', 'h-3.5 w-3.5 shrink-0 text-zinc-400')
                        <span class="fe-picker__name">{{ $file['name'] }}</span>
                        @unless ($isDisabled())
                            <button
                                type="button"
                                class="fe-picker__remove"
                                aria-label="{{ __('filament-file-explorer::file-explorer.picker.remove', ['name' => $file['name']]) }}"
                                x-on:click="$wire.set(
                                    @js($statePath),
                                    @js($isMultiple())
                                        ? ($wire.get(@js($statePath)) || []).filter((id) => Number(id) !== @js($file['id']))
                                        : null
                                )"
                            >
                                @svg('heroicon-m-x-mark', 'h-3 w-3')
                            </button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-dynamic-component>
