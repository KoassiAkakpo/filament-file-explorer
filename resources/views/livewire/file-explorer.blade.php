<div>
    @if(!$currentFolder)
        <div class="p-8 text-center text-zinc-500">{{ __('filament-file-explorer::file-explorer.empty') }}</div>
    @else
        @php
            $listing = $this->listing();
            $sortedFolders = $listing['folders'];
            $sortedMedia = $listing['files'];
            $abilities = $this->abilities();
            $locale = str_replace('_', '-', app()->getLocale());
            $i18n = trans('filament-file-explorer::file-explorer');
        @endphp
        <div
            class="w-full"
            x-data="FileExplorerUi({
                scopeKey: @js($scopeKey),
                rootFolderId: {{ $rootFolderId }},
                abilities: @js($abilities),
                mediaUrlBase: @js(url(config('filament-file-explorer.routes.prefix'))),
                refreshInterval: {{ $this->refreshInterval() }},
                componentId: @js($this->getId()),
                translations: @js($i18n),
                selectedFolders: @js($selectedFolders),
                selectedFiles: @js($selectedFiles),
            })"
            x-on:livewire-upload-start="onUploadStart()"
            x-on:livewire-upload-finish="onUploadFinish()"
            x-on:livewire-upload-cancel="onUploadCancel()"
            x-on:livewire-upload-error="onUploadError()"
            x-on:livewire-upload-progress="onUploadProgress($event.detail.progress)"
            @fe-upload-settled.window="onUploadSettled()"
            @fe-context.window="openContext($event.detail)"
            @fe-item-drag-start.window="onItemDragStart()"
            @fe-item-drag-end.window="isDraggingItems = false"
            @fe-upload-files.window="uploadDroppedFiles($event.detail.files)"
            @fe-sel-cleared.window="sel.replace([], [])"
            @fe-folder-created.window="sel.replace([Number($event.detail.folderId)], [])"
            @new-folder-created.window="focusInput('[data-fe-new-folder-input]', $event.detail)"
            @focus-rename-input.window="focusInput('[data-fe-rename-input]', $event.detail)"
            @click.window="closeContext()"
            @keydown.escape.window="closeContext(); $wire.cancelRename(); $wire.cancelNewFolder(); $wire.closeInfo(); $wire.cancelDelete(); $wire.closePreview()"
        >
            <div class="fe-finder w-full overflow-hidden rounded-xl border border-zinc-200/90 bg-zinc-50/80 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40" dir="ltr" lang="{{ $locale }}" translate="no">
                <div class="flex min-h-[560px]">
                    {{-- Sidebar --}}
                    <aside
                        class="fe-sidebar hidden shrink-0 flex-col border-e border-zinc-200/80 bg-zinc-50/90 dark:border-zinc-700 dark:bg-zinc-900/50 sm:flex"
                        x-bind:class="$store.feUi.sidebarOpen ? 'fe-sidebar--open' : 'fe-sidebar--closed'"
                    >
                        <div class="flex items-center gap-2 border-b border-zinc-200/80 px-2.5 py-2 dark:border-zinc-700" x-show="$store.feUi.sidebarOpen" x-cloak>
                            @svg('heroicon-o-folder', 'h-5 w-5 text-zinc-500')
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('filament-file-explorer::file-explorer.explorer') }}</span>
                        </div>
                        @php $rootOptions = $this->rootOptions(); @endphp
                        @if ($rootOptions !== [])
                            <div class="border-b border-zinc-200/80 px-1.5 py-2 dark:border-zinc-700" x-show="$store.feUi.sidebarOpen" x-cloak>
                                <div class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('filament-file-explorer::file-explorer.roots.label') }}</div>
                                <ul class="flex flex-col gap-0.5" role="listbox" aria-label="{{ __('filament-file-explorer::file-explorer.roots.label') }}">
                                    @foreach ($rootOptions as $rootOption)
                                        <li>
                                            <button
                                                type="button"
                                                class="fe-side-node flex w-full items-center gap-1.5 rounded-md px-1.5 py-1 text-start text-[12px]"
                                                role="option"
                                                aria-selected="{{ (int) $rootOption['id'] === (int) $rootFolderId ? 'true' : 'false' }}"
                                                @class(['bg-zinc-200/70 font-semibold dark:bg-zinc-700/60' => (int) $rootOption['id'] === (int) $rootFolderId])
                                                wire:click="switchRoot({{ (int) $rootOption['id'] }})"
                                                wire:key="fe-root-{{ $rootOption['id'] }}"
                                            >
                                                @svg('heroicon-o-server-stack', 'h-4 w-4 shrink-0 text-zinc-500')
                                                <span class="truncate">{{ $rootOption['name'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="flex-1 overflow-y-auto overflow-x-hidden px-1.5 py-2" x-show="$store.feUi.sidebarOpen" x-cloak>
                            <x-filament-file-explorer::file-explorer.sidebar-tree
                                :nodes="$this->sidebarTree()"
                                :current-id="$currentFolder->id"
                            />
                        </div>
                        @if ($quota = $this->quotaState())
                            <div class="border-t border-zinc-200/80 px-2.5 py-2 dark:border-zinc-700" x-show="$store.feUi.sidebarOpen" x-cloak>
                                <div class="flex items-center justify-between gap-2 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">
                                    <span>{{ __('filament-file-explorer::file-explorer.quota.label') }}</span>
                                    <span @class(['text-red-600 dark:text-red-400' => $quota['full']])>{{ $quota['percent'] }}%</span>
                                </div>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $quota['percent'] }}">
                                    <div @class([
                                            'h-full rounded-full',
                                            'bg-red-500' => $quota['full'],
                                            'bg-amber-500' => $quota['warning'] && ! $quota['full'],
                                            'bg-primary-500' => ! $quota['warning'],
                                        ]) style="width: {{ $quota['percent'] }}%"></div>
                                </div>
                                <div class="mt-1 text-[10px] text-zinc-500" translate="no">
                                    {{ $quota['full']
                                        ? __('filament-file-explorer::file-explorer.quota.full')
                                        : __('filament-file-explorer::file-explorer.quota.usage', ['used' => $quota['used_label'], 'limit' => $quota['limit_label']]) }}
                                </div>
                            </div>
                        @endif
                    </aside>

                    <div class="flex min-w-0 flex-1 flex-col">
                {{-- Toolbar --}}
                <div class="fe-toolbar border-b border-zinc-200/80 px-2.5 py-1.5 dark:border-zinc-700/80" dir="ltr">
                    <div class="fe-toolbar__primary flex shrink-0 items-center gap-0.5">
                        <button type="button" class="fe-tool-btn hidden sm:inline-flex" title="{{ __('filament-file-explorer::file-explorer.toolbar.toggle_sidebar') }}" @click="$store.feUi.sidebarOpen = !$store.feUi.sidebarOpen">
                            <span x-show="$store.feUi.sidebarOpen">@svg('heroicon-o-bars-3', 'h-4 w-4')</span>
                            <span x-show="!$store.feUi.sidebarOpen" x-cloak>@svg('heroicon-o-bars-3-bottom-left', 'h-4 w-4')</span>
                        </button>
                        <div class="mx-1 hidden h-4 w-px bg-zinc-200 sm:block dark:bg-zinc-600"></div>
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.back') }}" @disabled(!$this->canGoBack()) wire:click="goBack">
                            @svg('heroicon-o-chevron-left', 'h-4 w-4')
                        </button>
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.forward') }}" @disabled(!$this->canGoForward()) wire:click="goForward">
                            @svg('heroicon-o-chevron-right', 'h-4 w-4')
                        </button>
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.up') }}" @disabled((int) $this->currentFolder->id === (int) $this->rootFolderId) wire:click="navigateToParent">
                            @svg('heroicon-o-arrow-uturn-up', 'h-4 w-4')
                        </button>

                        <div class="mx-1 h-4 w-px bg-zinc-200 dark:bg-zinc-600"></div>

                        @if ($abilities['mkdir'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.new_folder') }}" wire:click="createNewFolder">
                            @svg('heroicon-o-folder-plus', 'h-4 w-4')
                        </button>
                        @endif

                        @if ($abilities['upload'])
                        <input
                            type="file"
                            name="files"
                            x-ref="fileInput"
                            multiple
                            class="hidden"
                            accept="{{ $this->acceptAttribute() }}"
                            wire:key="file-input-{{ $fileInputKey }}"
                            @change="pickAndUploadFiles($event)"
                        >

                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.upload') }}" @click="openFilePicker()">
                            @svg('heroicon-o-arrow-up-tray', 'h-4 w-4')
                        </button>

                        @if ($abilities['mkdir'])
                        {{-- webkitdirectory is what carries the folder structure: each
                             file arrives with a relative path the server rebuilds. --}}
                        <input
                            type="file"
                            x-ref="folderInput"
                            multiple
                            webkitdirectory
                            directory
                            class="hidden"
                            wire:key="folder-input-{{ $fileInputKey }}"
                            @change="pickAndUploadFolder($event)"
                        >

                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.upload_folder') }}" @click="openFolderPicker()">
                            @svg('heroicon-o-folder-arrow-down', 'h-4 w-4')
                        </button>
                        @endif
                        @endif
                    </div>

                    <div class="fe-toolbar__secondary fe-toolbar__collapse flex shrink-0 items-center gap-0.5">
                        <div class="mx-1 h-4 w-px bg-zinc-200 dark:bg-zinc-600"></div>

                        @if ($abilities['rename'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.rename') }}" x-cloak
                            x-show="(sel.folders.length + sel.files.length) === 1"
                            @click="toolbarRename()">
                            @svg('heroicon-o-pencil', 'h-3.5 w-3.5')
                        </button>
                        @endif
                        @if ($abilities['download'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.download') }}" x-cloak
                            x-show="(sel.folders.length + sel.files.length) > 0"
                            @click="toolbarDownload()">
                            @svg('heroicon-o-arrow-down-tray', 'h-3.5 w-3.5')
                        </button>
                        @endif
                        @if ($abilities['copy'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.copy') }}" x-cloak
                            x-show="(sel.folders.length + sel.files.length) > 0"
                            @click="toolbarCopy()">
                            @svg('heroicon-o-document-duplicate', 'h-3.5 w-3.5')
                        </button>
                        @endif
                        @if ($abilities['move'] || $abilities['copy'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.paste') }}" :disabled="!$wire.clipboardReady" @click="$wire.pasteClipboard()">
                            @svg('heroicon-o-clipboard-document', 'h-3.5 w-3.5')
                        </button>
                        @endif
                        @if ($abilities['getInfo'])
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.info') }}" x-cloak
                            x-show="(sel.folders.length + sel.files.length) > 0"
                            @click="toolbarInfo()">
                            @svg('heroicon-o-information-circle', 'h-3.5 w-3.5')
                        </button>
                        @endif
                        @if ($abilities['delete'] || $abilities['deleteFolder'])
                        <button type="button" class="fe-tool-btn fe-tool-btn--ghost-danger" title="{{ __('filament-file-explorer::file-explorer.toolbar.delete') }}" x-cloak
                            x-show="(sel.folders.length + sel.files.length) > 0"
                            @click="confirmDeleteSelected()">
                            @svg('heroicon-o-trash', 'h-3.5 w-3.5')
                        </button>
                        @endif
                    </div>

                    <div class="fe-toolbar__more relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.more_actions') }}" @click="open = !open">
                            @svg('heroicon-o-ellipsis-vertical', 'h-4 w-4')
                        </button>
                        <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            class="fe-menu absolute start-0 top-full z-30 mt-1 w-48 overflow-hidden py-1"
                        >
                            @if ($abilities['rename'])
                            <button type="button" class="fe-view-item" x-show="(sel.folders.length + sel.files.length) === 1" @click="toolbarRename(); open=false">
                                @svg('heroicon-o-pencil', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.rename') }}
                            </button>
                            @endif
                            @if ($abilities['download'])
                            <button type="button" class="fe-view-item" x-show="(sel.folders.length + sel.files.length) > 0" @click="toolbarDownload(); open=false">
                                @svg('heroicon-o-arrow-down-tray', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.download') }}
                            </button>
                            @endif
                            @if ($abilities['copy'])
                            <button type="button" class="fe-view-item" x-show="(sel.folders.length + sel.files.length) > 0" @click="toolbarCopy(); open=false">
                                @svg('heroicon-o-document-duplicate', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.copy') }}
                            </button>
                            @endif
                            @if ($abilities['move'] || $abilities['copy'])
                            <button type="button" class="fe-view-item" :disabled="!$wire.clipboardReady" @click="$wire.pasteClipboard(); open=false">
                                @svg('heroicon-o-clipboard-document', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.paste') }}
                            </button>
                            @endif
                            @if ($abilities['getInfo'])
                            <button type="button" class="fe-view-item" x-show="(sel.folders.length + sel.files.length) > 0" @click="toolbarInfo(); open=false">
                                @svg('heroicon-o-information-circle', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.info') }}
                            </button>
                            @endif
                            @if ($abilities['delete'] || $abilities['deleteFolder'])
                            <button type="button" class="fe-view-item text-red-600 dark:text-red-400" x-show="(sel.folders.length + sel.files.length) > 0" @click="confirmDeleteSelected(); open=false">
                                @svg('heroicon-o-trash', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.toolbar.delete') }}
                            </button>
                            @endif
                        </div>
                    </div>

                    @if ($this->trashEnabled())
                    <button
                        type="button"
                        @class(['fe-tool-btn', 'fe-tool-btn--active' => $showTrash])
                        title="{{ __('filament-file-explorer::file-explorer.toolbar.trash') }}"
                        aria-pressed="{{ $showTrash ? 'true' : 'false' }}"
                        wire:click="toggleTrash"
                    >
                        @svg('heroicon-o-trash', 'h-4 w-4')
                    </button>
                    @endif

                    <div class="fe-toolbar__spacer min-w-0 flex-1"></div>

                    <div class="fe-toolbar__end flex min-w-0 items-center gap-1">
                        <span class="me-1 hidden min-w-0 truncate text-[11px] text-zinc-400 sm:inline" x-cloak
                              x-show="(sel.folders.length + sel.files.length) > 0"
                              x-text="translations?.toolbar?.selected_count
                                  ? translations.toolbar.selected_count.replace(':count', sel.folders.length + sel.files.length)
                                  : (sel.folders.length + sel.files.length) + ' selected'"></span>

                        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.sort_by') }}" @click="open = !open">
                                @svg('heroicon-o-arrows-up-down', 'h-3.5 w-3.5')
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0"
                                x-transition:enter-end="opacity-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="fe-menu absolute end-0 top-full z-30 mt-1 w-44 overflow-hidden py-1"
                            >
                                <button type="button" class="fe-view-item {{ $sortBy === 'name' ? 'fe-view-item--active' : '' }}" wire:click="setSort('name')" @click="open=false">
                                    @svg('heroicon-o-document-text', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.sort.name') }}
                                    @if ($sortBy === 'name')
                                        <span class="ms-auto text-[10px] text-teal-600">{{ $sortDir === 'asc' ? __('filament-file-explorer::file-explorer.sort.asc') : __('filament-file-explorer::file-explorer.sort.desc') }}</span>
                                    @endif
                                </button>
                                <button type="button" class="fe-view-item {{ $sortBy === 'date' ? 'fe-view-item--active' : '' }}" wire:click="setSort('date')" @click="open=false">
                                    @svg('heroicon-o-calendar', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.sort.date') }}
                                    @if ($sortBy === 'date')
                                        <span class="ms-auto text-[10px] text-teal-600">{{ $sortDir === 'asc' ? __('filament-file-explorer::file-explorer.sort.old') : __('filament-file-explorer::file-explorer.sort.new') }}</span>
                                    @endif
                                </button>
                                <button type="button" class="fe-view-item {{ $sortBy === 'type' ? 'fe-view-item--active' : '' }}" wire:click="setSort('type')" @click="open=false">
                                    @svg('heroicon-o-document', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.sort.type') }}
                                    @if ($sortBy === 'type')
                                        <span class="ms-auto text-[10px] text-teal-600">{{ $sortDir === 'asc' ? __('filament-file-explorer::file-explorer.sort.asc') : __('filament-file-explorer::file-explorer.sort.desc') }}</span>
                                    @endif
                                </button>
                            </div>
                        </div>

                        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="fe-tool-btn" title="{{ __('filament-file-explorer::file-explorer.toolbar.view_options') }}" @click="open = !open">
                                @if ($viewMode === 'list')
                                    @svg('heroicon-o-list-bullet', 'h-3.5 w-3.5')
                                @elseif ($viewMode === 'table')
                                    @svg('heroicon-o-table-cells', 'h-3.5 w-3.5')
                                @elseif ($viewMode === 'details')
                                    @svg('heroicon-o-bars-3', 'h-3.5 w-3.5')
                                @else
                                    @svg('heroicon-o-squares-2x2', 'h-3.5 w-3.5')
                                @endif
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0"
                                x-transition:enter-end="opacity-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="fe-menu absolute end-0 top-full z-30 mt-1 w-44 overflow-hidden py-1"
                            >
                                <button type="button" class="fe-view-item {{ $viewMode === 'grid' ? 'fe-view-item--active' : '' }}" wire:click="setViewMode('grid')" @click="open=false">@svg('heroicon-o-squares-2x2', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.view.icons') }}</button>
                                <button type="button" class="fe-view-item {{ $viewMode === 'list' ? 'fe-view-item--active' : '' }}" wire:click="setViewMode('list')" @click="open=false">@svg('heroicon-o-list-bullet', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.view.list') }}</button>
                                <button type="button" class="fe-view-item {{ $viewMode === 'table' ? 'fe-view-item--active' : '' }}" wire:click="setViewMode('table')" @click="open=false">@svg('heroicon-o-table-cells', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.view.columns') }}</button>
                                <button type="button" class="fe-view-item {{ $viewMode === 'details' ? 'fe-view-item--active' : '' }}" wire:click="setViewMode('details')" @click="open=false">@svg('heroicon-o-bars-3', 'h-3.5 w-3.5') {{ __('filament-file-explorer::file-explorer.view.details') }}</button>
                            </div>
                        </div>
                        @if ($abilities['search'])
                        <div class="relative min-w-0 shrink">
                            @svg('heroicon-o-magnifying-glass', 'pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-400')
                            <input wire:model.live.debounce.250ms="search" class="fe-input fe-search h-8 pe-3 ps-8 text-xs" type="search" title="{{ __('filament-file-explorer::file-explorer.toolbar.search') }}" placeholder="{{ __('filament-file-explorer::file-explorer.toolbar.search_placeholder') }}">
                        </div>
                        @endif
                    </div>
                </div>

                <div class="fe-browser relative overflow-x-hidden dark:bg-zinc-800/50" x-bind:class="dropingFile ? 'fe-browser--drop' : ''">
                    @if ($showTrash)
                        @php $trashRows = $this->trashItems(); @endphp
                        <div class="flex items-center justify-between gap-2 border-b border-zinc-200/80 px-3 py-2 dark:border-zinc-700">
                            <div class="flex items-center gap-2 text-[13px] font-medium text-zinc-700 dark:text-zinc-200">
                                @svg('heroicon-o-trash', 'h-4 w-4 text-zinc-400')
                                <span>{{ __('filament-file-explorer::file-explorer.trash.title') }}</span>
                                <span class="text-[11px] font-normal text-zinc-400">{{ trans_choice('filament-file-explorer::file-explorer.trash.count', count($trashRows)) }}</span>
                            </div>
                            @if ($trashRows !== [] && ($abilities['delete'] || $abilities['deleteFolder']))
                                <button type="button" class="fe-btn fe-btn--danger" wire:click="requestPurge">
                                    {{ __('filament-file-explorer::file-explorer.trash.empty') }}
                                </button>
                            @endif
                        </div>

                        <div class="min-h-[500px] p-2">
                            @if ($trashRows === [])
                                <p class="p-8 text-center text-sm text-zinc-500">{{ __('filament-file-explorer::file-explorer.trash.empty_state') }}</p>
                            @else
                                <ul class="space-y-0.5">
                                    @foreach ($trashRows as $row)
                                        <li class="fe-trash-row" wire:key="trash-{{ $row['type'] }}-{{ $row['id'] }}">
                                            <div class="flex min-w-0 items-center gap-2">
                                                @if ($row['type'] === 'folder')
                                                    <x-filament-file-explorer::file-explorer.folder-icon class="h-6 w-6 shrink-0" />
                                                @else
                                                    <x-filament-file-explorer::file-explorer.mime-icon :icon="$row['icon']" size="sm" />
                                                @endif
                                                <span class="truncate text-[13px] text-zinc-800 dark:text-zinc-100">{{ $row['name'] }}</span>
                                            </div>
                                            <span class="truncate text-xs text-zinc-500">{{ __('filament-file-explorer::file-explorer.trash.origin') }}: {{ $row['path'] }}</span>
                                            <span class="text-xs text-zinc-500">{{ $row['deleted_at'] }}</span>
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button type="button" class="fe-btn" wire:click="restoreItem('{{ $row['type'] }}', {{ $row['id'] }})">
                                                    {{ __('filament-file-explorer::file-explorer.trash.restore') }}
                                                </button>
                                                <button type="button" class="fe-tool-btn fe-tool-btn--ghost-danger" title="{{ __('filament-file-explorer::file-explorer.trash.purge') }}" wire:click="requestPurge('{{ $row['type'] }}', {{ $row['id'] }})">
                                                    @svg('heroicon-o-trash', 'h-3.5 w-3.5')
                                                </button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @else
                    @if($search)
                        <div class="border-b border-zinc-200 bg-zinc-100/80 px-4 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 sm:px-5">{{ trans_choice('filament-file-explorer::file-explorer.search_results', $listing['total']) }}</div>
                    @endif

                    <div
                        data-fe-items
                        tabindex="0"
                        role="listbox"
                        aria-multiselectable="true"
                        aria-label="{{ __('filament-file-explorer::file-explorer.explorer') }}"
                        @keydown="onKeydown($event)"
                        @mousedown.left="initiateDrawing($event)"
                        @if ($abilities['upload'])
                        x-on:drop="dropingFile = false"
                        x-on:drop.prevent="handleFileDrop($event)"
                        x-on:dragover.prevent="dropingFile = true"
                        x-on:dragleave.prevent="dropingFile = false"
                        @endif
                        @if ($abilities['mkdir'])
                        x-on:dblclick.self="$wire.createNewFolder()"
                        @endif
                        x-on:click="handleContainerClick($event)"
                        x-on:contextmenu.prevent="openEmptyContext($event)"
                        @class([
                            'relative min-h-[500px] select-none overflow-y-auto p-2 pb-8',
                            'flex flex-wrap content-start gap-x-0 gap-y-1' => in_array($viewMode, ['grid', 'icons'], true),
                            'space-y-0.5' => in_array($viewMode, ['list', 'table', 'details'], true),
                        ])
                    >
                        <div
                            x-show="drawnArea"
                            x-cloak
                            class="drawn-area absolute z-20"
                            :style="drawnArea ? {
                                left: drawnArea.left + 'px',
                                top: drawnArea.top + 'px',
                                width: drawnArea.width + 'px',
                                height: drawnArea.height + 'px'
                            } : {}"
                        ></div>
                        @if ($isCreatingNewFolder)
                            @if (in_array($viewMode, ['list', 'table', 'details'], true))
                                <div class="flex items-center gap-3 rounded-lg bg-teal-50/70 px-3 py-2 dark:bg-teal-950/20" @click.outside="$wire.cancelNewFolder">
                                    <x-filament-file-explorer::file-explorer.folder-icon class="h-7 w-7 shrink-0" />
                                    <input type="text" data-fe-new-folder-input wire:model="newFolderName" wire:keydown.enter.prevent="saveNewFolder" wire:keydown.escape.prevent="cancelNewFolder" class="fe-input w-full px-2 py-1 text-sm">
                                </div>
                            @else
                                <div class="fe-icon-item relative z-30 mx-0.5 flex w-[96px] flex-col items-center px-0.5 pt-0.5 pb-0.5 text-center" @click.outside="$wire.cancelNewFolder">
                                    <div class="fe-icon-well fe-icon-well--selected flex h-[68px] w-[76px] items-center justify-center rounded-xl">
                                        <x-filament-file-explorer::file-explorer.folder-icon class="h-[3.35rem] w-[3.35rem]" />
                                    </div>
                                    <input type="text" data-fe-new-folder-input wire:model="newFolderName" wire:keydown.enter.prevent="saveNewFolder" wire:keydown.escape.prevent="cancelNewFolder" class="fe-input fe-rename-input mt-1.5 w-full px-1 py-0.5 text-center text-[11px]">
                                    @error('newFolderName')
                                        <span class="text-xs text-red-500">{{ $message }}</span>
                                    @enderror
                                </div>
                            @endif
                        @endif

                        @php
                            $isRowView = in_array($viewMode, ['list', 'table', 'details'], true);
                            $cols = match ($viewMode) {
                                'details' => 'grid-cols-[minmax(0,1fr)_6rem_7rem_8rem]',
                                'table' => 'grid-cols-[minmax(0,1fr)_6rem_8rem]',
                                default => 'grid-cols-[minmax(0,1fr)_7rem_8rem]',
                            };
                        @endphp

                        @if ($isRowView)
                            <div class="fe-list-head mb-0 grid {{ $cols }} gap-2 border-b border-zinc-200/80 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                                <button type="button" class="text-start hover:text-zinc-700 dark:hover:text-zinc-200" wire:click="setSort('name')">{{ __('filament-file-explorer::file-explorer.sort.name') }} @if($sortBy==='name'){{ $sortDir==='asc'?'↑':'↓' }}@endif</button>
                                <button type="button" class="text-start hover:text-zinc-700 dark:hover:text-zinc-200" wire:click="setSort('type')">{{ __('filament-file-explorer::file-explorer.sort.kind') }} @if($sortBy==='type'){{ $sortDir==='asc'?'↑':'↓' }}@endif</button>
                                @if ($viewMode === 'details')
                                    <span>{{ __('filament-file-explorer::file-explorer.size') }}</span>
                                @endif
                                <button type="button" class="text-start hover:text-zinc-700 dark:hover:text-zinc-200" wire:click="setSort('date')">{{ __('filament-file-explorer::file-explorer.sort.date_modified') }} @if($sortBy==='date'){{ $sortDir==='asc'?'↑':'↓' }}@endif</button>
                            </div>

                            @php $detailRow = 0; @endphp
                            @foreach($sortedFolders as $folder)
                                @php
                                    $folderRenaming = $renamingType === 'folder' && (int) $renamingId === (int) $folder->id;
                                    $folderSelected = in_array($folder->id, $selectedFolders, false);
                                    $folderItems = (int) ($folder->children_count ?? 0) + (int) ($folder->file_explorer_count ?? 0);
                                    $isStripe = $viewMode === 'details' && ($detailRow % 2 === 1);
                                    $detailRow++;
                                @endphp
                                <div
                                    wire:key="row-folder-{{ $folder->id }}-{{ $viewMode }}"
                                    x-data="{ isDragOver: false }"
                                    data-fe-drop-folder="{{ $folder->id }}"
                                    @unless($folderRenaming)
                                        x-on:pointerdown="$store.feDrag.pointerDown($event, 'folder', {{ $folder->id }}, @js($folder->name), $wire, sel)"
                                        x-on:click.stop="
                                            if ($store.feDrag.consumeClickSuppression()) return;
                                            sel.click('folder', {{ $folder->id }}, $event, $el);
                                        "
                                        x-on:dblclick.stop="$wire.navigateToFolder({{ $folder->id }})"
                                    @endunless
                                    x-on:dragover.prevent="
                                        if ($event.dataTransfer.types.includes('Files')) {
                                            $event.dataTransfer.dropEffect = 'copy';
                                            isDragOver = true;
                                        }
                                    "
                                    x-on:dragleave.prevent="isDragOver = false"
                                    x-on:drop.prevent="$store.feDrag.dropFilesOnFolder($event, {{ $folder->id }}, $wire); isDragOver = false"
                                    x-on:contextmenu.stop.prevent="
                                        if (!sel.hasFolder({{ $folder->id }})) { sel.toggle('folder', {{ $folder->id }}, false); }
                                        $dispatch('fe-context', { type: 'folder', id: {{ $folder->id }}, name: @js($folder->name), x: $event.clientX, y: $event.clientY })
                                    "
                                    @class([
                                        'folder fe-list-row grid cursor-default items-center gap-2 px-3 py-1.5 text-[13px] transition-colors duration-75 ' . $cols,
                                        'rounded-lg border border-zinc-100 dark:border-zinc-700/60' => $viewMode === 'table',
                                        'rounded-none' => $viewMode === 'details',
                                        'fe-list-row--stripe' => $isStripe,
                                    ])
                                    :class="{
                                        'fe-row--selected': (sel.hasFolder({{ $folder->id }}) || sel.inMarqueeFolder({{ $folder->id }})) && !@js($folderRenaming),
                                        'drag-hover': sel.inMarqueeFolder({{ $folder->id }}),
                                        'hover:bg-zinc-100/80 dark:hover:bg-white/5': !sel.hasFolder({{ $folder->id }}) && !sel.inMarqueeFolder({{ $folder->id }}),
                                        'ring-2 ring-zinc-300/50 bg-zinc-100/70': isDragOver || $store.feDrag.dropTargetId === {{ $folder->id }},
                                        'fe-dragging': $store.feDrag.active && sel.hasFolder({{ $folder->id }})
                                    }"
                                    data-id="{{ $folder->id }}"
                                    data-fe-type="folder"
                                    tabindex="-1"
                                    role="option"
                                    :aria-selected="sel.hasFolder({{ $folder->id }}) ? 'true' : 'false'"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        <x-filament-file-explorer::file-explorer.folder-icon class="h-6 w-6 shrink-0" />
                                        @if ($folderRenaming)
                                            <input type="text" data-fe-rename-input wire:model="renameValue" wire:keydown.enter.prevent="saveRename" wire:keydown.escape.prevent="cancelRename" wire:blur="saveRename" class="fe-input fe-rename-input w-full px-1.5 py-0.5 text-[13px]" @click.stop @mousedown.stop>
                                        @else
                                            <span class="truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $folder->name }}</span>
                                        @endif
                                    </div>
                                    <span class="text-xs text-zinc-500">{{ __('filament-file-explorer::file-explorer.folder_kind') }}</span>
                                    @if ($viewMode === 'details')
                                        <span class="text-xs text-zinc-500">{{ trans_choice('filament-file-explorer::file-explorer.items_count', $folderItems) }}</span>
                                    @endif
                                    <span class="text-xs text-zinc-500">{{ $folder->updated_at?->format('Y/m/d H:i') }}</span>
                                </div>
                            @endforeach

                            @foreach($sortedMedia as $media)
                                @php
                                    $fileRenaming = $renamingType === 'file' && (int) $renamingId === (int) $media->id;
                                    $fileSelected = in_array($media->id, $selectedFiles, false);
                                    $fileLabel = \Koassi\FilamentFileExplorer\Support\MediaLabel::display($media);
                                    $mimeIcon = \Koassi\FilamentFileExplorer\Support\MimeIcon::forMedia($media);
                                    // Through the media route, never getUrl(): that is a raw
                                    // disk URL and would skip both guards.
                                    $listPreview = str_starts_with((string) $media->mime_type, 'image/')
                                        ? $this->mediaThumbnailUrl($media->id)
                                        : null;
                                    $sizeLabel = number_format(((int) $media->size) / 1024, 1) . ' KB';
                                    $isStripe = $viewMode === 'details' && ($detailRow % 2 === 1);
                                    $detailRow++;
                                @endphp
                                <div
                                    wire:key="row-file-{{ $media->id }}-{{ $viewMode }}"
                                    @unless($fileRenaming)
                                        x-on:pointerdown="$store.feDrag.pointerDown($event, 'file', {{ $media->id }}, @js($fileLabel), $wire, sel)"
                                        x-on:click.stop="
                                            if ($store.feDrag.consumeClickSuppression()) return;
                                            sel.click('file', {{ $media->id }}, $event, $el);
                                        "
                                        x-on:dblclick.stop="openFile({{ $media->id }})"
                                    @endunless
                                    x-on:contextmenu.stop.prevent="
                                        if (!sel.hasFile({{ $media->id }})) { sel.toggle('file', {{ $media->id }}, false); }
                                        $dispatch('fe-context', { type: 'file', id: {{ $media->id }}, name: @js($fileLabel), x: $event.clientX, y: $event.clientY })
                                    "
                                    @class([
                                        'file fe-list-row grid cursor-default items-center gap-2 px-3 py-1.5 text-[13px] transition-colors duration-75 ' . $cols,
                                        'rounded-lg border border-zinc-100 dark:border-zinc-700/60' => $viewMode === 'table',
                                        'rounded-none' => $viewMode === 'details',
                                        'fe-list-row--stripe' => $isStripe,
                                    ])
                                    :class="{
                                        'fe-row--selected': (sel.hasFile({{ $media->id }}) || sel.inMarqueeFile({{ $media->id }})) && !@js($fileRenaming),
                                        'drag-hover': sel.inMarqueeFile({{ $media->id }}),
                                        'hover:bg-zinc-100/80 dark:hover:bg-white/5': !sel.hasFile({{ $media->id }}) && !sel.inMarqueeFile({{ $media->id }}),
                                        'fe-dragging': $store.feDrag.active && sel.hasFile({{ $media->id }})
                                    }"
                                    data-id="{{ $media->id }}"
                                    data-fe-type="file"
                                    tabindex="-1"
                                    role="option"
                                    :aria-selected="sel.hasFile({{ $media->id }}) ? 'true' : 'false'"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        @if ($listPreview)
                                            <img src="{{ $listPreview }}" alt="" draggable="false" loading="lazy" class="pointer-events-none h-5 w-5 object-contain">
                                        @else
                                            <x-filament-file-explorer::file-explorer.mime-icon :icon="$mimeIcon" size="sm" />
                                        @endif
                                        @if ($fileRenaming)
                                            <input type="text" data-fe-rename-input wire:model="renameValue" wire:keydown.enter.prevent="saveRename" wire:keydown.escape.prevent="cancelRename" wire:blur="saveRename" class="fe-input fe-rename-input w-full px-1.5 py-0.5 text-[13px]" @click.stop @mousedown.stop>
                                        @else
                                            <span class="truncate text-zinc-800 dark:text-zinc-100">{{ $fileLabel }}</span>
                                        @endif
                                    </div>
                                    <span class="truncate text-xs text-zinc-500">{{ strtoupper(pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'FILE') }}</span>
                                    @if ($viewMode === 'details')
                                        <span class="text-xs text-zinc-500">{{ $sizeLabel }}</span>
                                    @endif
                                    <span class="text-xs text-zinc-500">{{ $media->created_at?->format('Y/m/d H:i') }}</span>
                                </div>
                            @endforeach
                        @else
                            @foreach($sortedFolders as $folder)
                                <x-filament-file-explorer::file-explorer.directory
                                    :folder="$folder"
                                    :selectedFolders="$selectedFolders"
                                    :selectedFiles="$selectedFiles"
                                    :renamingType="$renamingType"
                                    :renamingId="$renamingId"
                                    wire:key="folder-{{ $folder->id }}"
                                />
                            @endforeach

                            @foreach($sortedMedia as $media)
                                <x-filament-file-explorer::file-explorer.media
                                    :media="$media"
                                    :selectedFiles="$selectedFiles"
                                    :selectedFolders="$selectedFolders"
                                    :openUrl="$this->mediaOpenUrl($media->id)"
                                    :previewUrl="$this->mediaOpenUrl($media->id)"
                                    :thumbUrl="$this->mediaThumbnailUrl($media->id)"
                                    :renamingType="$renamingType"
                                    :renamingId="$renamingId"
                                    wire:key="file-{{ $media->id }}"
                                />
                            @endforeach
                        @endif

                        @if ($listing['hasMore'])
                            <div class="fe-load-more-bar w-full px-3 py-4 text-center" wire:key="fe-load-more">
                                <button type="button" class="fe-load-more" wire:click="loadMore" wire:loading.attr="disabled">
                                    {{ __('filament-file-explorer::file-explorer.listing.load_more') }}
                                </button>
                                <div class="mt-1.5 text-[11px] text-zinc-400">
                                    {{ __('filament-file-explorer::file-explorer.listing.showing', ['shown' => $listing['shown'], 'total' => $listing['total']]) }}
                                </div>
                            </div>
                        @endif
                    </div>

                    @endif

                    <div class="pointer-events-none absolute inset-x-3 bottom-3 z-20" x-cloak x-show="$store.feUpload.visible"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0">
                        <div class="overflow-hidden rounded-lg border bg-white/95 px-3 py-2 shadow-lg backdrop-blur dark:bg-zinc-900/95"
                             :class="$store.feUpload.status === 'error' || $store.feUpload.status === 'cancelled'
                                ? 'border-red-200 dark:border-red-800'
                                : ($store.feUpload.status === 'done' ? 'border-emerald-200 dark:border-emerald-800' : 'border-zinc-200 dark:border-zinc-700')">
                            <div class="mb-1.5 flex items-center justify-between gap-2 text-[11px]"
                                 :class="$store.feUpload.status === 'error' || $store.feUpload.status === 'cancelled' ? 'text-red-600 dark:text-red-400' : ($store.feUpload.status === 'done' ? 'text-emerald-600' : 'text-zinc-500')">
                                <span x-text="$store.feUpload.label"></span>
                                <span x-show="$store.feUpload.status === 'uploading'" x-text="Math.round($store.feUpload.progress) + '%'"></span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full transition-all duration-200"
                                     :class="$store.feUpload.status === 'error' || $store.feUpload.status === 'cancelled' ? 'bg-red-500' : ($store.feUpload.status === 'done' ? 'bg-emerald-500' : 'bg-sky-500')"
                                     :style="'width:' + ($store.feUpload.status === 'error' || $store.feUpload.status === 'cancelled' ? 100 : $store.feUpload.progress) + '%'"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="flex select-none items-center gap-0.5 border-t border-zinc-100 px-2 py-1.5 text-xs dark:border-zinc-700/80 dark:text-zinc-300">
                    @foreach ($breadcrumb as $index => $folder)
                        <button
                            type="button"
                            class="flex items-center gap-x-1 rounded-lg px-2 py-1 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10"
                            @unless($loop->last)
                                data-fe-drop-folder="{{ $folder->id }}"
                            @endunless
                            @if ($loop->last) aria-current="location" @endif
                            :class="{ 'fe-side-item--drop bg-zinc-100 dark:bg-white/10': $store.feDrag.dropTargetId === {{ (int) $folder->id }} && {{ $loop->last ? 'false' : 'true' }} }"
                            wire:click.prevent="navigateToBreadcrumb({{ $index }})"
                        >
                            <x-filament-file-explorer::file-explorer.folder-icon class="h-3.5 w-3.5" /> <span>{{ $folder->name }}</span>
                        </button>
                        @if (!$loop->last)
                            @svg('heroicon-o-chevron-left', 'h-3 w-3 shrink-0 rotate-180 text-zinc-300')
                        @endif
                    @endforeach
                </nav>
                    </div>{{-- end main column --}}

                    {{-- Get Info inspector --}}
                    @if ($showInfoModal && $infoItem)
                        <aside class="fe-inspector flex w-[300px] shrink-0 flex-col"
                               dir="ltr"
                               lang="{{ $locale }}"
                               translate="no"
                               wire:key="info-{{ $locale }}-{{ $infoItem['type'] ?? 'x' }}-{{ $infoItem['id'] ?? 0 }}"
                               x-data x-init="$el.animate([{opacity:0,transform:'translateX(8px)'},{opacity:1,transform:'translateX(0)'}],{duration:160,easing:'cubic-bezier(.2,.8,.2,1)'})">
                            <div class="fe-inspector-head flex items-center justify-between gap-2 px-3 py-2.5">
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500" translate="no">{{ __('filament-file-explorer::file-explorer.inspector.title') }}</span>
                                <button type="button" class="fe-tool-btn" wire:click="closeInfo" title="{{ __('filament-file-explorer::file-explorer.inspector.close') }}">
                                    @svg('heroicon-o-x-mark', 'h-3.5 w-3.5')
                                </button>
                            </div>
                            <div class="flex flex-1 flex-col gap-3 overflow-y-auto p-3">
                                <div class="fe-inspector-hero flex flex-col items-center px-2 py-3 text-center">
                                    @if (($infoItem['type'] ?? '') === 'folder' || ($infoItem['type'] ?? '') === 'multi')
                                        <x-filament-file-explorer::file-explorer.folder-icon class="mb-2 h-16 w-16" />
                                    @elseif (!empty($infoItem['preview']))
                                        <img src="{{ $infoItem['preview'] }}" alt="" class="mb-2 h-20 w-20 rounded-lg object-cover ring-1 ring-black/5">
                                    @else
                                        <x-filament-file-explorer::file-explorer.mime-icon :icon="$infoItem['icon'] ?? 'file'" size="lg" class="mb-2" />
                                    @endif
                                    <div class="w-full truncate text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">{{ $infoItem['name'] }}</div>
                                    <div class="mt-0.5 text-[11px] text-zinc-400">{{ $infoItem['mime'] }}</div>
                                </div>
                                <dl class="fe-inspector-meta" translate="no">
                                    <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.size') }}</dt><dd>{{ $infoItem['size'] }}</dd></div>
                                    <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.where') }}</dt><dd class="break-all text-end">{{ $infoItem['path'] }}</dd></div>
                                    <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.permissions') }}</dt><dd class="text-end">{{ $infoItem['permissions'] }}</dd></div>
                                    @if (!empty($infoItem['added_by']))
                                        <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.added_by') }}</dt><dd class="break-all text-end">{{ $infoItem['added_by'] }}</dd></div>
                                    @endif
                                    @if (!empty($infoItem['delete_note']))
                                        <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.delete') }}</dt><dd class="text-end text-amber-700 dark:text-amber-300">{{ $infoItem['delete_note'] }}</dd></div>
                                    @endif
                                    <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.created') }}</dt><dd>{{ $infoItem['created'] }}</dd></div>
                                    <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.modified') }}</dt><dd>{{ $infoItem['updated'] }}</dd></div>
                                    @if (!empty($infoItem['extra']))
                                        <div class="fe-inspector-row"><dt>{{ __('filament-file-explorer::file-explorer.inspector.details') }}</dt><dd class="break-all text-end">{{ $infoItem['extra'] }}</dd></div>
                                    @endif
                                </dl>
                            </div>
                        </aside>
                    @endif
                </div>{{-- end flex row --}}
            </div>

            {{-- Delete confirmation --}}
            @if ($deleteRequest)
                @php $confirmId = 'fe-confirm-'.$this->getId(); @endphp
                <div class="fe-overlay" wire:key="fe-confirm">
                    <div
                        class="fe-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="{{ $confirmId }}"
                        x-data
                        x-init="$nextTick(() => $refs.feConfirmDefault?.focus())"
                        @keydown.tab.prevent="trapTab($event, $el)"
                    >
                        @php $mode = $deleteRequest['mode'] ?? 'delete'; @endphp
                        <h2 id="{{ $confirmId }}" class="fe-modal__title">
                            {{ trans_choice('filament-file-explorer::file-explorer.confirm.'.$mode.'_title', max(1, $deleteRequest['deletable'])) }}
                        </h2>

                        @if ($deleteRequest['deletable'] > 0)
                            <ul class="fe-modal__list">
                                @foreach ($deleteRequest['names'] as $name)
                                    <li class="truncate">{{ $name }}</li>
                                @endforeach
                                @if ($deleteRequest['more'] > 0)
                                    <li class="text-zinc-400">{{ trans_choice('filament-file-explorer::file-explorer.confirm.delete_more', $deleteRequest['more']) }}</li>
                                @endif
                            </ul>

                            @if ($deleteRequest['nested_count'] > 0)
                                <p class="fe-modal__note">{{ trans_choice('filament-file-explorer::file-explorer.confirm.delete_nested', $deleteRequest['nested_count']) }}</p>
                            @endif

                            <p class="fe-modal__note">{{ __('filament-file-explorer::file-explorer.confirm.'.$mode.'_body') }}</p>
                        @else
                            <p class="fe-modal__note">{{ __('filament-file-explorer::file-explorer.confirm.nothing_deletable') }}</p>
                        @endif

                        @if ($deleteRequest['blocked'] !== [])
                            <div class="fe-modal__blocked">
                                <p class="font-medium">{{ __('filament-file-explorer::file-explorer.confirm.blocked') }}</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($deleteRequest['blocked'] as $item)
                                        <li class="truncate"><span class="font-medium">{{ $item['name'] }}</span> — {{ $item['reason'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="fe-modal__actions">
                            <button
                                type="button"
                                class="fe-btn"
                                x-ref="{{ $deleteRequest['deletable'] > 0 ? 'feConfirmCancel' : 'feConfirmDefault' }}"
                                wire:click="cancelDelete"
                            >{{ __('filament-file-explorer::file-explorer.confirm.cancel') }}</button>

                            @if ($deleteRequest['deletable'] > 0)
                                <button
                                    type="button"
                                    class="fe-btn fe-btn--danger"
                                    x-ref="feConfirmDefault"
                                    wire:click="confirmDelete"
                                >{{ __('filament-file-explorer::file-explorer.confirm.'.$mode) }}</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Preview lightbox --}}
            @if ($previewItem)
                <div
                    class="fe-lightbox"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ $previewItem['name'] }}"
                    tabindex="-1"
                    wire:key="fe-preview-{{ $previewItem['id'] }}"
                    x-data
                    x-init="$nextTick(() => $el.focus())"
                    @keydown.arrow-left.prevent="$wire.previewShift(-1)"
                    @keydown.arrow-right.prevent="$wire.previewShift(1)"
                    @keydown.tab.prevent="trapTab($event, $el)"
                >
                    <div class="fe-lightbox__bar">
                        <div class="min-w-0">
                            <div class="truncate text-[13px] font-medium">{{ $previewItem['name'] }}</div>
                            <div class="text-[11px] opacity-70">
                                {{ $previewItem['mime'] }} · {{ $previewItem['size'] }}
                                @if ($previewItem['total'] > 1 && $previewItem['position'] > 0)
                                    · {{ __('filament-file-explorer::file-explorer.lightbox.position', ['position' => $previewItem['position'], 'total' => $previewItem['total']]) }}
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @if ($previewItem['total'] > 1)
                                <button type="button" class="fe-lightbox__btn" title="{{ __('filament-file-explorer::file-explorer.lightbox.previous') }}" wire:click="previewShift(-1)">
                                    @svg('heroicon-o-chevron-left', 'h-4 w-4')
                                </button>
                                <button type="button" class="fe-lightbox__btn" title="{{ __('filament-file-explorer::file-explorer.lightbox.next') }}" wire:click="previewShift(1)">
                                    @svg('heroicon-o-chevron-right', 'h-4 w-4')
                                </button>
                            @endif
                            <a class="fe-lightbox__btn" title="{{ __('filament-file-explorer::file-explorer.lightbox.download') }}" href="{{ $previewItem['download_url'] }}">
                                @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                            </a>
                            <a class="fe-lightbox__btn" title="{{ __('filament-file-explorer::file-explorer.lightbox.open_new_tab') }}" href="{{ $previewItem['url'] }}" target="_blank" rel="noopener">
                                @svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4')
                            </a>
                            <button type="button" class="fe-lightbox__btn" title="{{ __('filament-file-explorer::file-explorer.lightbox.close') }}" wire:click="closePreview">
                                @svg('heroicon-o-x-mark', 'h-4 w-4')
                            </button>
                        </div>
                    </div>

                    <div class="fe-lightbox__stage" @click.self="$wire.closePreview()">
                        @switch ($previewItem['kind'])
                            @case ('image')
                                <img class="fe-lightbox__image" src="{{ $previewItem['url'] }}" alt="{{ $previewItem['name'] }}">
                                @break
                            @case ('video')
                                <video class="fe-lightbox__image" src="{{ $previewItem['url'] }}" controls preload="metadata"></video>
                                @break
                            @case ('audio')
                                <audio class="w-full max-w-xl" src="{{ $previewItem['url'] }}" controls preload="metadata"></audio>
                                @break
                            @case ('frame')
                                <iframe class="fe-lightbox__frame" src="{{ $previewItem['url'] }}" title="{{ $previewItem['name'] }}"></iframe>
                                @break
                            @default
                                <div class="flex flex-col items-center gap-3 text-center text-zinc-300">
                                    <x-filament-file-explorer::file-explorer.mime-icon :icon="$previewItem['icon']" size="lg" />
                                    <p class="text-sm">{{ __('filament-file-explorer::file-explorer.lightbox.unsupported') }}</p>
                                    <a class="fe-btn" href="{{ $previewItem['download_url'] }}">{{ __('filament-file-explorer::file-explorer.lightbox.download') }}</a>
                                </div>
                        @endswitch
                    </div>
                </div>
            @endif

            {{-- Context menu --}}
            <div
                x-show="ctx.open"
                x-cloak
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fe-context-menu fixed z-[100] min-w-[220px] origin-top-left overflow-visible py-1"
                dir="ltr"
                :style="{ left: ctx.x + 'px', top: ctx.y + 'px' }"
                @click.stop
                @contextmenu.prevent
            >
                <template x-if="ctx.type === 'empty'">
                    <div>
                        <template x-if="abilities.mkdir">
                            <button type="button" class="fe-ctx-item" @click="run(() => $wire.createNewFolder())">
                                @svg('heroicon-o-folder-plus', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.new_folder') }}
                            </button>
                        </template>
                        <template x-if="abilities.upload">
                            <button type="button" class="fe-ctx-item" @click="run(() => openFilePicker())">
                                @svg('heroicon-o-arrow-up-tray', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.upload_files') }}
                            </button>
                        </template>
                        <template x-if="abilities.move || abilities.copy">
                            <div>
                                <div class="fe-ctx-sep" x-show="abilities.mkdir || abilities.upload"></div>
                                <button type="button" class="fe-ctx-item" :disabled="!$wire.clipboardReady" @click="run(() => $wire.pasteClipboard())">
                                    @svg('heroicon-o-clipboard-document', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.paste') }}
                                </button>
                            </div>
                        </template>
                        <template x-if="abilities.getInfo">
                            <div>
                                <div class="fe-ctx-sep"></div>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.showInfo())">
                                    @svg('heroicon-o-information-circle', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.info') }}
                                </button>
                            </div>
                        </template>
                        <div class="fe-ctx-sep"></div>

                        <div
                            class="fe-ctx-flyout"
                            x-data="qfCtxFlyout()"
                            @mouseenter="show()"
                            @mouseleave="hide()"
                        >
                            <button type="button" class="fe-ctx-item fe-ctx-item--fly" tabindex="-1" @click.prevent>
                                <span class="inline-flex items-center gap-2">@svg('heroicon-o-squares-2x2', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.view.view') }}</span>
                                @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5 opacity-40')
                            </button>
                            <div class="fe-ctx-sub" x-show="open" x-cloak
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0"
                                 x-transition:enter-end="opacity-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0">
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setViewMode('grid'))">@svg('heroicon-o-squares-2x2', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.view.icons') }}</button>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setViewMode('list'))">@svg('heroicon-o-list-bullet', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.view.list') }}</button>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setViewMode('table'))">@svg('heroicon-o-table-cells', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.view.columns') }}</button>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setViewMode('details'))">@svg('heroicon-o-bars-3', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.view.details') }}</button>
                            </div>
                        </div>

                        <div
                            class="fe-ctx-flyout"
                            x-data="qfCtxFlyout()"
                            @mouseenter="show()"
                            @mouseleave="hide()"
                        >
                            <button type="button" class="fe-ctx-item fe-ctx-item--fly" tabindex="-1" @click.prevent>
                                <span class="inline-flex items-center gap-2">@svg('heroicon-o-arrows-up-down', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.sort.sort_by') }}</span>
                                @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5 opacity-40')
                            </button>
                            <div class="fe-ctx-sub" x-show="open" x-cloak
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0"
                                 x-transition:enter-end="opacity-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0">
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setSort('name'))">{{ __('filament-file-explorer::file-explorer.sort.name') }}</button>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setSort('date'))">{{ __('filament-file-explorer::file-explorer.sort.date') }}</button>
                                <button type="button" class="fe-ctx-item" @click="run(() => $wire.setSort('type'))">{{ __('filament-file-explorer::file-explorer.sort.type') }}</button>
                            </div>
                        </div>
                    </div>
                </template>

                <template x-if="ctx.type === 'folder' || ctx.type === 'file'">
                    <div>
                        <button type="button" class="fe-ctx-item" x-show="ctx.type === 'folder'" @click="run(() => $wire.openFolder(ctx.id))">
                            @svg('heroicon-o-folder-open', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.open') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="ctx.type === 'folder'" @click="run(() => { const u = new URL(window.location.href); u.searchParams.set('folder', ctx.id); window.open(u.toString(), '_blank'); })">
                            @svg('heroicon-o-arrow-top-right-on-square', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.open_new_tab') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="ctx.type === 'file'" @click="run(() => openFile(ctx.id))">
                            @svg('heroicon-o-eye', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.open') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="ctx.type === 'file'" @click="run(() => window.open(fileUrl(ctx.id, false), '_blank'))">
                            @svg('heroicon-o-arrow-top-right-on-square', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.open_new_tab') }}
                        </button>
                        <div class="fe-ctx-sep" x-show="abilities.rename || abilities.copy || abilities.move"></div>
                        <button type="button" class="fe-ctx-item" x-show="abilities.rename" @click="run(() => $wire.startRename(ctx.type, ctx.id))">
                            @svg('heroicon-o-pencil', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.rename') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="abilities.copy" @click="run(() => $wire.copySelection(ctx.type === 'folder' ? ctx.id : null, ctx.type === 'file' ? ctx.id : null))">
                            @svg('heroicon-o-document-duplicate', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.copy') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="abilities.move" @click="run(() => $wire.cutSelection(ctx.type === 'folder' ? ctx.id : null, ctx.type === 'file' ? ctx.id : null))">
                            @svg('heroicon-o-scissors', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.cut') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="abilities.move || abilities.copy" :disabled="!$wire.clipboardReady" @click="run(() => $wire.pasteClipboard())">
                            @svg('heroicon-o-clipboard-document', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.paste') }}
                        </button>
                        <div class="fe-ctx-sep" x-show="abilities.download"></div>
                        <button type="button" class="fe-ctx-item" x-show="abilities.download && ctx.type === 'file'" @click="run(() => window.location.href = fileUrl(ctx.id, true))">
                            @svg('heroicon-o-arrow-down-tray', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.download') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="abilities.download && ctx.type === 'folder'" @click="run(() => window.location.href = folderZipUrl(ctx.id))">
                            @svg('heroicon-o-arrow-down-tray', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.download_zip') }}
                        </button>
                        <button type="button" class="fe-ctx-item" x-show="abilities.download" @click="run(() => window.location.href = ctx.type === 'folder' ? folderZipUrl(ctx.id) : mediaZipUrl(ctx.id))">
                            @svg('heroicon-o-archive-box', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.compress_zip') }}
                        </button>
                        <div class="fe-ctx-sep" x-show="abilities.getInfo"></div>
                        <button type="button" class="fe-ctx-item" x-show="abilities.getInfo" @click="run(() => $wire.showInfo(ctx.type, ctx.id))">
                            @svg('heroicon-o-information-circle', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.info') }}
                        </button>
                        <div class="fe-ctx-sep" x-show="(abilities.delete && ctx.type === 'file') || (abilities.deleteFolder && ctx.type === 'folder')"></div>
                        <button type="button" class="fe-ctx-item fe-ctx-danger"
                            x-show="(abilities.delete && ctx.type === 'file') || (abilities.deleteFolder && ctx.type === 'folder')"
                            :disabled="!ctx.canDelete || (ctx.type === 'folder' && ctx.id === rootFolderId && (sel.folders.length + sel.files.length) <= 1)"
                            :title="ctx.deleteHint || ''"
                            @click="run(() => confirmDeleteSelected())">
                            @svg('heroicon-o-trash', 'fe-ctx-icon') {{ __('filament-file-explorer::file-explorer.context.delete') }}
                        </button>
                        <div
                            x-show="((abilities.delete && ctx.type === 'file') || (abilities.deleteFolder && ctx.type === 'folder')) && ctx.deleteHint"
                            class="px-3 pb-1.5 text-[10px] leading-snug text-zinc-500 dark:text-zinc-400"
                            x-text="ctx.deleteHint"
                        ></div>
                    </div>
                </template>
            </div>
        </div>
    @endif
</div>
