@php $storage = $this->getStorage(); @endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="fe-widget" dir="ltr">
            <div class="fe-widget__head">
                <span class="fe-widget__title">{{ __('filament-file-explorer::file-explorer.widget.title') }}</span>
                @if ($storage && $storage['url'])
                    <a href="{{ $storage['url'] }}" class="fe-widget__link">
                        {{ __('filament-file-explorer::file-explorer.widget.open') }}
                    </a>
                @endif
            </div>

            @if (! $storage)
                {{-- canView() has already said yes, so this is the narrow window
                     where the scope stopped resolving between the two calls: a
                     tenant switched, a root purged. A widget says so rather than
                     failing the dashboard around it. --}}
                <p class="fe-widget__empty">{{ __('filament-file-explorer::file-explorer.widget.unavailable') }}</p>
            @else
                <div class="fe-widget__figure">{{ $storage['limit'] ? $storage['limit']['used_label'] : $storage['used_label'] }}</div>

                @if ($storage['limit'])
                    <div class="fe-widget__bar">
                        <div
                            @class([
                                'fe-widget__fill',
                                'fe-widget__fill--warning' => $storage['limit']['warning'] && ! $storage['limit']['full'],
                                'fe-widget__fill--full' => $storage['limit']['full'],
                            ])
                            style="width: {{ $storage['limit']['percent'] }}%"
                        ></div>
                    </div>
                    <p class="fe-widget__meta">
                        {{ __('filament-file-explorer::file-explorer.widget.of_limit', [
                            'percent' => $storage['limit']['percent'],
                            'limit' => $storage['limit']['limit_label'],
                        ]) }}
                    </p>
                @else
                    {{-- No cap set. The total is still the useful half, and a bar
                         with nothing to fill would only invent a limit. --}}
                    <p class="fe-widget__meta">{{ __('filament-file-explorer::file-explorer.widget.no_limit') }}</p>
                @endif

                <p class="fe-widget__meta">{{ trans_choice('filament-file-explorer::file-explorer.widget.files', $storage['files']) }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
