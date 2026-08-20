<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Livewire;


use Koassi\FilamentFileExplorer\Support\FileNames;
use Koassi\FilamentFileExplorer\Support\FolderListing;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;

use Koassi\FilamentFileExplorer\Support\MediaLabel;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\MimeIcon;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FileExplorer extends \Livewire\Component
{
    use WithFileUploads;

    public string $scopeKey = 'default';

    public ?Folder $currentFolder = null;

    /** @var list<Folder> */
    public array $breadcrumb = [];

    /** @var list<int> */
    public array $selectedFolders = [];

    /** @var list<int> */
    public array $selectedFiles = [];

    public string $search = '';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $files = [];

    public bool $isCreatingNewFolder = false;

    public string $newFolderName = '';

    public int $rootFolderId = 0;

    public string $viewMode = 'grid';

    /**
     * How many items the listing currently shows. Grown by loadMore() rather
     * than paged, so selection and drag targets already on screen stay put.
     */
    public int $perPage = 0;

    /** @var array{folders: \Illuminate\Database\Eloquent\Collection<int, Folder>, files: \Illuminate\Database\Eloquent\Collection<int, Media>, shown: int, total: int, hasMore: bool}|null */
    private ?array $listingCache = null;

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    public ?string $renamingType = null;

    public ?int $renamingId = null;

    public string $renameValue = '';

    /** @var array{type:?string,id:?int,name:?string,size:?string,path:?string,mime:?string,permissions:?string,created:?string,updated:?string,extra:?string,preview:?string,icon?:string}|null */
    public ?array $infoItem = null;

    public bool $showInfoModal = false;

    /**
     * Pending delete, summarised for the confirmation dialog. Built server-side
     * so it can report what is actually about to disappear — nested contents
     * included — and which items the authorizer refuses.
     *
     * @var array{folders: list<int>, files: list<int>, names: list<string>, more: int, nested_count: int, deletable: int, blocked: list<array{name: string, reason: string}>}|null
     */
    public ?array $deleteRequest = null;

    /** @var array{id: int, name: string, mime: string, kind: string, url: string, download_url: string, size: string, icon: string, position: int, total: int}|null */
    public ?array $previewItem = null;

    /** Whether the browser area shows the trash instead of the current folder. */
    public bool $showTrash = false;

    public bool $clipboardReady = false;

    public ?int $uploadTargetFolderId = null;

    /**
     * Per-file paths sent by a folder upload, in the same order as $files.
     * User input: sanitised in resolveUploadFolder().
     *
     * @var list<string>
     */
    public array $uploadRelativePaths = [];

    /** Folders a folder upload had to create, for the closing notification. */
    private int $uploadCreatedFolders = 0;

    /** Copies or uploads the quota refused, for the summary notification. */
    private int $quotaRefused = 0;

    /** Announced at most once per request, however many mutations it made. */
    private bool $announced = false;

    public int $fileInputKey = 0;

    /** @var list<int> */
    public array $navHistory = [];

    public int $navIndex = -1;

    public function mount(?string $scopeKey = null, ?int $rootFolderId = null): void
    {
        $this->scopeKey = (string) ($scopeKey ?? $this->scopeKey);
        $this->rootFolderId = (int) ($rootFolderId ?? $this->rootFolderId);

        $authorizer = app(FileExplorerAuthorizer::class);

        abort_unless($authorizer->canAccess($this->scopeKey, $this->rootFolderId), 403);

        // The media routes take the scope key from their own URL and cannot
        // trust it, so record the root it resolved to while it is still
        // trustworthy. See Support\ScopeRoots.
        ScopeRoots::remember($this->scopeKey, $this->rootFolderId);

        $this->clipboardReady = $this->hasClipboard();
        $this->restoreViewPreferences();

        $sessionKey = $this->sessionKey();
        $requestedFolder = request()->integer('folder') ?: null;
        $currentId = $requestedFolder ?: session($sessionKey, $this->rootFolderId);

        $folder = Folder::with(['children', 'parent'])->find($currentId);

        if (! $folder || ! app(FolderTree::class)->isUnderRoot($folder, $this->rootFolderId)) {
            $folder = Folder::with(['children', 'parent'])->findOrFail($this->rootFolderId);
            session([$sessionKey => $folder->id]);
        }

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->navHistory = [(int) $folder->id];
        $this->navIndex = 0;
        $this->perPage = $this->pageSize();
    }

    protected function sessionKey(): string
    {
        return 'currentFolderId.'.$this->scopeKey;
    }

    protected function viewKey(): string
    {
        return 'view.'.$this->scopeKey;
    }

    /**
     * View mode and sort survive navigation and reloads, per scope, the same
     * way the current folder does.
     */
    protected function restoreViewPreferences(): void
    {
        $prefs = session($this->viewKey());

        if (! is_array($prefs)) {
            return;
        }

        // Validated even though the session is server-side: a stored value can
        // outlive the view mode it names.
        $this->setViewMode((string) ($prefs['viewMode'] ?? $this->viewMode));
        $this->setSort(
            (string) ($prefs['sortBy'] ?? $this->sortBy),
            (string) ($prefs['sortDir'] ?? $this->sortDir),
        );
    }

    protected function rememberViewPreferences(): void
    {
        session([$this->viewKey() => [
            'viewMode' => $this->viewMode,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
        ]]);
    }

    protected function clipboardKey(): string
    {
        return 'clipboard.'.$this->scopeKey;
    }


    protected function assertUnderRoot(?Folder $folder): void
    {
        abort_unless(
            $folder && app(FolderTree::class)->isUnderRoot($folder, $this->rootFolderId),
            403
        );
    }

    /**
     * Media ids arrive as user input, so a row is only in scope when it hangs
     * off a Folder of this explorer's collection *and* that folder sits under
     * the root. Resolving the folder and skipping the check when it is missing
     * would leave every media row of every other model reachable.
     */
    protected function assertMediaUnderRoot(Media $media): Folder
    {
        abort_unless($media->model_type === (new Folder)->getMorphClass(), 403);
        abort_unless($media->collection_name === UploadRules::collection(), 403);

        $folder = Folder::query()->find($media->model_id);

        // Aborts when the folder is missing or out of scope, so what comes back
        // is always the media's in-scope folder.
        $this->assertUnderRoot($folder);

        return $folder;
    }

    public function setViewMode(string $mode): void
    {
        if ($mode === 'icons') {
            $mode = 'grid';
        }

        $this->viewMode = in_array($mode, ['grid', 'list', 'table', 'details'], true) ? $mode : 'grid';

        $this->rememberViewPreferences();
    }

    public function acceptAttribute(): string
    {
        return implode(',', UploadRules::acceptedFileTypes());
    }

    /**
     * @return array{
     *     browse: bool,
     *     search: bool,
     *     getInfo: bool,
     *     download: bool,
     *     upload: bool,
     *     mkdir: bool,
     *     rename: bool,
     *     move: bool,
     *     copy: bool,
     *     delete: bool,
     *     deleteFolder: bool
     * }
     */
    public function abilities(): array
    {
        return app(FileExplorerAuthorizer::class)->abilities($this->scopeKey, $this->rootFolderId);
    }

    protected function authorizer(): FileExplorerAuthorizer
    {
        return app(FileExplorerAuthorizer::class);
    }

    protected function ability(string $key): bool
    {
        return (bool) ($this->abilities()[$key] ?? false);
    }

    protected function rules(): array
    {
        return [
            'files.*' => [
                'file',
                'max:'.UploadRules::maxSizeKb(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        $fail(__('filament-file-explorer::file-explorer.validation.invalid_file'));

                        return;
                    }

                    if (! UploadRules::isAllowedUpload($value)) {
                        $fail(__('filament-file-explorer::file-explorer.validation.invalid_format'));
                    }
                },
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'files.*.file' => __('filament-file-explorer::file-explorer.validation.invalid_file'),
            'files.*.max' => __('filament-file-explorer::file-explorer.validation.file_too_large', ['max' => UploadRules::maxSizeKb()]),
        ];
    }

    public function updatedSearch(): void
    {
        abort_unless($this->ability('search'), 403);

        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('fe-sel-cleared');
        $this->resetListing();
    }

    public function canGoBack(): bool
    {
        return $this->navIndex > 0;
    }

    public function canGoForward(): bool
    {
        return $this->navIndex >= 0 && $this->navIndex < count($this->navHistory) - 1;
    }

    public function goBack(): void
    {
        if (! $this->canGoBack()) {
            return;
        }

        $this->navIndex--;
        $this->openFolderFromHistory((int) $this->navHistory[$this->navIndex]);
    }

    public function goForward(): void
    {
        if (! $this->canGoForward()) {
            return;
        }

        $this->navIndex++;
        $this->openFolderFromHistory((int) $this->navHistory[$this->navIndex]);
    }

    protected function pushNavHistory(int $folderId): void
    {
        if (($this->navHistory[$this->navIndex] ?? null) === $folderId) {
            return;
        }

        if ($this->navIndex < count($this->navHistory) - 1) {
            $this->navHistory = array_values(array_slice($this->navHistory, 0, $this->navIndex + 1));
        }

        $this->navHistory[] = $folderId;
        $this->navIndex = count($this->navHistory) - 1;
    }

    protected function openFolderFromHistory(int $folderId): void
    {
        $folder = Folder::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);

        $this->search = '';
        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('reset-folder');
        $this->dispatch('fe-sel-cleared');

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->resetListing();
        session([$this->sessionKey() => $folder->id]);
    }

    public function setSort(string $by, ?string $dir = null): void
    {
        if (! in_array($by, ['name', 'date', 'type'], true)) {
            return;
        }

        $this->listingCache = null;

        if ($dir === null) {
            if ($this->sortBy === $by) {
                $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                $this->sortBy = $by;
                $this->sortDir = 'asc';
            }

            $this->rememberViewPreferences();

            return;
        }

        $this->sortBy = $by;
        $this->sortDir = $dir === 'desc' ? 'desc' : 'asc';

        $this->rememberViewPreferences();
    }

    public function handleMediaClick($fileId): void
    {
        // Selection only — no detail panel.
    }

    public function handleFolderClick($folderId): void
    {
        // Selection only — no detail panel.
    }

    public function setSelection($folders, $files): void
    {
        $this->selectedFolders = array_values(array_map('intval', (array) $folders));
        $this->selectedFiles = array_values(array_map('intval', (array) $files));
    }

    public function clearSelection(): void
    {
        $this->selectedFolders = [];
        $this->selectedFiles = [];
    }

    public function hasClipboard(): bool
    {
        $clip = session($this->clipboardKey());

        return is_array($clip)
            && (($clip['folders'] ?? []) !== [] || ($clip['files'] ?? []) !== []);
    }

    public function clipboardMode(): ?string
    {
        $clip = session($this->clipboardKey());

        return is_array($clip) ? ($clip['mode'] ?? null) : null;
    }

    public function copySelection(?int $folderId = null, ?int $fileId = null): void
    {
        abort_unless($this->ability('copy'), 403);
        $this->writeClipboard('copy', $folderId, $fileId);
        Notification::make()->success()->title(__('filament-file-explorer::file-explorer.copied'))->send();
    }

    public function cutSelection(?int $folderId = null, ?int $fileId = null): void
    {
        abort_unless($this->ability('move'), 403);
        $this->writeClipboard('cut', $folderId, $fileId);
        Notification::make()->success()->title(__('filament-file-explorer::file-explorer.cut'))->send();
    }

    protected function writeClipboard(string $mode, ?int $folderId, ?int $fileId): void
    {
        $folders = $folderId !== null ? [$folderId] : $this->selectedFolders;
        $files = $fileId !== null ? [$fileId] : $this->selectedFiles;

        if ($folderId !== null) {
            $files = [];
        }
        if ($fileId !== null) {
            $folders = [];
        }

        $folders = array_values(array_filter($folders, fn ($id) => (int) $id !== $this->rootFolderId));

        session([$this->clipboardKey() => [
            'mode' => $mode,
            'folders' => array_map('intval', $folders),
            'files' => array_map('intval', $files),
        ]]);
        $this->clipboardReady = $this->hasClipboard();
    }

    public function pasteClipboard(): void
    {
        $docs = app(FileExplorerAuthorizer::class);
        $this->assertUnderRoot($this->currentFolder);

        $clip = session($this->clipboardKey());
        if (! is_array($clip)) {
            return;
        }

        $mode = $clip['mode'] ?? 'copy';
        $folderIds = $clip['folders'] ?? [];
        $fileIds = $clip['files'] ?? [];

        if ($mode === 'cut') {
            abort_unless($this->ability('move'), 403);
            $this->moveItemsToFolder($this->currentFolder->id, $folderIds, $fileIds);
            session()->forget($this->clipboardKey());
            $this->clipboardReady = false;
            Notification::make()->success()->title(__('filament-file-explorer::file-explorer.pasted'))->send();

            return;
        }

        abort_unless($this->ability('copy'), 403);

        $this->quotaRefused = 0;

        foreach ($folderIds as $folderId) {
            $source = Folder::query()->find($folderId);
            if (! $source || (int) $source->id === $this->rootFolderId) {
                continue;
            }
            $this->assertUnderRoot($source);
            $this->copyFolderRecursive($source, $this->currentFolder);
        }

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);
            if (! $media) {
                continue;
            }
            $this->assertMediaUnderRoot($media);
            $this->duplicateMedia($media, $this->currentFolder);
        }

        $this->currentFolder = $this->currentFolder->fresh(['children']);
        $this->afterMutation();
        Notification::make()->success()->title(__('filament-file-explorer::file-explorer.pasted'))->send();
        $this->notifyQuotaRefused();
    }

    /**
     * @return bool false when the copy would not fit in the scope's quota
     */
    protected function duplicateMedia(Media $media, Folder $target): bool
    {
        $path = $media->getPath();
        if (! is_file($path)) {
            return false;
        }

        // A copy adds as many bytes as the original takes.
        if (! app(Quota::class)->reserve($this->rootFolderId, (int) $media->size)) {
            $this->quotaRefused++;

            return false;
        }

        // A copy never overwrites, whatever the upload conflict policy says:
        // duplicating something is not meant to replace it.
        $index = FileNames::availableIndex($target, (string) $media->file_name);

        $actor = auth()->user();
        $target
            ->addMedia($path)
            ->preservingOriginal()
            ->usingName(FileNames::applyIndexToLabel((string) $media->name, $index))
            ->usingFileName(FileNames::applyIndex((string) $media->file_name, $index))
            ->withCustomProperties(array_merge($media->custom_properties ?? [], [
                'user_id' => $actor?->getAuthIdentifier(),
                'uploaded_by_type' => $actor ? $actor::class : null,
                'uploaded_by_id' => $actor?->getAuthIdentifier(),
            ]))
            ->toMediaCollection(UploadRules::collection());

        return true;
    }

    protected function copyFolderRecursive(Folder $source, Folder $parent): Folder
    {
        $name = $source->name;
        $slug = Str::slug($name) ?: ('folder-'.Str::lower(Str::random(6)));
        $baseSlug = $slug;
        $i = 1;
        while (Folder::query()->where('parent_id', $parent->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $name = $source->name.' ('.$i.')';
            $i++;
        }

        $folder = new Folder;
        $folder->name = $name;
        $folder->slug = $slug;
        $folder->parent_id = $parent->id;
        $folder->save();

        foreach ($source->getMedia(UploadRules::collection()) as $media) {
            $this->duplicateMedia($media, $folder);
        }

        foreach ($source->children as $child) {
            $this->copyFolderRecursive($child, $folder);
        }

        return $folder;
    }

    public function startRename(?string $type = null, ?int $id = null): void
    {
        abort_unless($this->ability('rename'), 403);

        if ($type === 'folder' && $id) {
            if ((int) $id === $this->rootFolderId) {
                Notification::make()->warning()->title(__('filament-file-explorer::file-explorer.root_not_renamable'))->send();

                return;
            }
            $folder = Folder::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $this->renamingType = 'folder';
            $this->renamingId = $id;
            $this->renameValue = $folder->name;
            $this->dispatch('focus-rename-input');

            return;
        }

        if ($type === 'file' && $id) {
            $media = Media::query()->findOrFail($id);
            $this->assertMediaUnderRoot($media);
            $this->renamingType = 'file';
            $this->renamingId = $id;
            $this->renameValue = $media->name ?: pathinfo($media->file_name, PATHINFO_FILENAME);
            $this->dispatch('focus-rename-input');

            return;
        }

        if (count($this->selectedFolders) === 1 && count($this->selectedFiles) === 0) {
            $this->startRename('folder', (int) $this->selectedFolders[0]);
        } elseif (count($this->selectedFiles) === 1 && count($this->selectedFolders) === 0) {
            $this->startRename('file', (int) $this->selectedFiles[0]);
        }
    }

    public function cancelRename(): void
    {
        $this->renamingType = null;
        $this->renamingId = null;
        $this->renameValue = '';
    }

    public function saveRename(): void
    {
        abort_unless($this->ability('rename'), 403);

        $name = trim($this->renameValue);
        if ($name === '' || ! $this->renamingType || ! $this->renamingId) {
            $this->cancelRename();

            return;
        }

        if ($this->renamingType === 'folder') {
            if ((int) $this->renamingId === $this->rootFolderId) {
                $this->cancelRename();

                return;
            }
            $folder = Folder::query()->findOrFail($this->renamingId);
            $this->assertUnderRoot($folder);
            $slug = Str::slug($name) ?: ('folder-'.Str::lower(Str::random(6)));
            $exists = Folder::query()
                ->where('parent_id', $folder->parent_id)
                ->where('slug', $slug)
                ->where('id', '!=', $folder->id)
                ->exists();
            if ($exists) {
                Notification::make()->danger()->title(__('filament-file-explorer::file-explorer.duplicate_folder'))->send();

                return;
            }
            $folder->name = $name;
            $folder->slug = $slug;
            $folder->save();
        } else {
            $media = Media::query()->findOrFail($this->renamingId);
            $this->assertMediaUnderRoot($media);
            $media->name = $name;
            $media->save();
        }

        $this->cancelRename();
        $this->currentFolder = $this->currentFolder->fresh(['children']);
        $this->afterMutation();
    }

    /**
     * Prepares the confirmation dialog for the current selection.
     *
     * Nothing is deleted here: confirmDelete() re-enters deleteItems(), which
     * remains the only place enforcing the delete rules.
     */
    public function requestDelete(array $folders = [], array $files = []): void
    {
        abort_unless($this->ability('delete') || $this->ability('deleteFolder'), 403);

        if ($folders !== [] || $files !== []) {
            $this->setSelection($folders, $files);
        }

        $folderIds = array_values(array_unique(array_map('intval', $this->selectedFolders)));
        $fileIds = array_values(array_unique(array_map('intval', $this->selectedFiles)));

        if ($folderIds === [] && $fileIds === []) {
            $this->deleteRequest = null;

            return;
        }

        $authorizer = app(FileExplorerAuthorizer::class);
        $names = [];
        $blocked = [];
        $nested = 0;
        $deletable = 0;

        foreach ($folderIds as $folderId) {
            $folder = Folder::query()->find($folderId);

            if (! $folder) {
                continue;
            }

            $this->assertUnderRoot($folder);

            if ((int) $folder->id === $this->rootFolderId) {
                $blocked[] = [
                    'name' => (string) $folder->name,
                    'reason' => __('filament-file-explorer::file-explorer.root_not_deletable'),
                ];

                continue;
            }

            $state = $authorizer->folderDeleteState($this->scopeKey, $folder);

            if (! $state['allowed']) {
                $blocked[] = [
                    'name' => (string) $folder->name,
                    'reason' => (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.folder_delete_denied')),
                ];

                continue;
            }

            $names[] = (string) $folder->name;
            $nested += $this->folderContentCount($folder);
            $deletable++;
        }

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertMediaUnderRoot($media);

            $state = $authorizer->mediaDeleteState($this->scopeKey, $media);

            if (! $state['allowed']) {
                $blocked[] = [
                    'name' => MediaLabel::display($media),
                    'reason' => (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.file_delete_denied')),
                ];

                continue;
            }

            $names[] = MediaLabel::display($media);
            $deletable++;
        }

        $this->deleteRequest = [
            'mode' => Trash::enabled() ? 'trash' : 'delete',
            'folders' => $folderIds,
            'files' => $fileIds,
            'names' => array_slice($names, 0, 5),
            'more' => max(0, count($names) - 5),
            'nested_count' => $nested,
            'deletable' => $deletable,
            'blocked' => $blocked,
        ];
    }

    public function confirmDelete(): void
    {
        if ($this->deleteRequest === null) {
            return;
        }

        $request = $this->deleteRequest;
        $this->deleteRequest = null;

        if (($request['mode'] ?? null) === 'purge') {
            $this->purgeItems($request['folders'], $request['files']);

            return;
        }

        $this->selectedFolders = $request['folders'];
        $this->selectedFiles = $request['files'];

        $this->deleteItems();
    }

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    */

    public function trashEnabled(): bool
    {
        return Trash::enabled();
    }

    public function toggleTrash(): void
    {
        abort_unless($this->ability('browse'), 403);

        $this->showTrash = Trash::enabled() && ! $this->showTrash;
        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('fe-sel-cleared');
    }

    /**
     * Rows for the trash view: what was removed, where it came from, and when.
     *
     * @return list<array{type: string, id: int, name: string, path: string, deleted_at: string, meta: string, icon: string}>
     */
    public function trashItems(): array
    {
        abort_unless($this->ability('browse'), 403);

        $trash = app(Trash::class);
        $items = $trash->items($this->rootFolderId);
        $rows = [];

        foreach ($items['folders'] as $folder) {
            $rows[] = [
                'type' => 'folder',
                'id' => (int) $folder->id,
                'name' => (string) $folder->name,
                'path' => $this->trashOriginPath($folder->parent_id),
                'deleted_at' => $folder->deleted_at?->format('Y/m/d H:i') ?? '—',
                'meta' => __('filament-file-explorer::file-explorer.folder_kind'),
                'icon' => 'folder',
            ];
        }

        foreach ($items['files'] as $media) {
            $trashedAt = $media->getCustomProperty('trashed_at');

            $rows[] = [
                'type' => 'file',
                'id' => (int) $media->id,
                'name' => MediaLabel::display($media),
                'path' => $this->trashOriginPath((int) $media->model_id),
                'deleted_at' => is_string($trashedAt)
                    ? \Illuminate\Support\Carbon::parse($trashedAt)->format('Y/m/d H:i')
                    : '—',
                'meta' => $this->formatBytes((int) $media->size),
                'icon' => MimeIcon::forMedia($media),
            ];
        }

        return $rows;
    }

    public function trashCount(): int
    {
        return Trash::enabled() ? app(Trash::class)->count($this->rootFolderId) : 0;
    }

    public function restoreItem(string $type, int $id): void
    {
        $trash = app(Trash::class);

        if ($type === 'folder') {
            // Restoring is the mirror of deleting, so it takes the same ability.
            abort_unless($this->ability('deleteFolder'), 403);

            $folder = Folder::withTrashed()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $trash->restoreFolder($folder);
        } else {
            abort_unless($this->ability('delete'), 403);

            $media = Media::query()->findOrFail($id);
            $this->assertTrashedMediaUnderRoot($media);
            $trash->restoreMedia($media);
        }

        $this->afterMutation();

        Notification::make()
            ->success()
            ->title(__('filament-file-explorer::file-explorer.trash.restored'))
            ->send();
    }

    /**
     * Confirmation for a permanent delete, from the trash view.
     */
    public function requestPurge(?string $type = null, ?int $id = null): void
    {
        abort_unless($this->ability('delete') || $this->ability('deleteFolder'), 403);

        $rows = collect($this->trashItems());

        if ($type !== null && $id !== null) {
            $rows = $rows->where('type', $type)->where('id', $id)->values();
        }

        if ($rows->isEmpty()) {
            $this->deleteRequest = null;

            return;
        }

        $this->deleteRequest = [
            'mode' => 'purge',
            'folders' => $rows->where('type', 'folder')->pluck('id')->map('intval')->all(),
            'files' => $rows->where('type', 'file')->pluck('id')->map('intval')->all(),
            'names' => $rows->pluck('name')->take(5)->all(),
            'more' => max(0, $rows->count() - 5),
            'nested_count' => 0,
            'deletable' => $rows->count(),
            'blocked' => [],
        ];
    }

    /**
     * @param  list<int>  $folderIds
     * @param  list<int>  $fileIds
     */
    protected function purgeItems(array $folderIds, array $fileIds): void
    {
        $trash = app(Trash::class);
        $purged = 0;

        foreach ($folderIds as $folderId) {
            abort_unless($this->ability('deleteFolder'), 403);

            $folder = Folder::withTrashed()->find($folderId);

            if (! $folder || ! $folder->trashed()) {
                continue;
            }

            $this->assertUnderRoot($folder);
            $trash->purgeFolder($folder);
            $purged++;
        }

        foreach ($fileIds as $fileId) {
            abort_unless($this->ability('delete'), 403);

            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertTrashedMediaUnderRoot($media);
            $trash->purgeMedia($media);
            $purged++;
        }

        $this->afterMutation();

        if ($purged > 0) {
            Notification::make()
                ->success()
                ->title(trans_choice('filament-file-explorer::file-explorer.trash.purged', $purged))
                ->send();
        }
    }

    /**
     * Same three conditions as assertMediaUnderRoot(), against the trash
     * collection: a trashed row is out of the live one, so it would fail there.
     */
    protected function assertTrashedMediaUnderRoot(Media $media): void
    {
        abort_unless($media->model_type === (new Folder)->getMorphClass(), 403);
        abort_unless($media->collection_name === Trash::collection(), 403);

        $this->assertUnderRoot(Folder::withTrashed()->find($media->model_id));
    }

    protected function trashOriginPath(?int $folderId): string
    {
        if ($folderId === null) {
            return '—';
        }

        $folder = Folder::withTrashed()->find($folderId);

        return $folder ? $this->folderPathString($folder) : '—';
    }

    public function cancelDelete(): void
    {
        $this->deleteRequest = null;
    }

    /**
     * Everything inside a folder — nested folders and their files — so the
     * dialog can say what a recursive delete actually takes with it.
     */
    protected function folderContentCount(Folder $folder): int
    {
        $ids = app(FolderTree::class)->descendantFolderIdsIncludingRoot((int) $folder->id);

        $files = (int) Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', (new Folder)->getMorphClass())
            ->whereIn('model_id', $ids)
            ->count();

        return (count($ids) - 1) + $files;
    }

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    */

    /**
     * Opens the lightbox on one file.
     *
     * Gated on the download ability: the preview streams the same bytes as a
     * download, through the same route.
     */
    public function preview(int $mediaId): void
    {
        abort_unless($this->ability('download'), 403);

        $media = Media::query()->findOrFail($mediaId);
        $this->assertMediaUnderRoot($media);

        $mime = (string) $media->mime_type;
        $ids = $this->listing()['files']->map(fn (Media $file): int => (int) $file->id)->all();
        $position = array_search((int) $media->id, $ids, true);

        $this->previewItem = [
            'id' => (int) $media->id,
            'name' => MediaLabel::display($media),
            'mime' => $mime !== '' ? $mime : '—',
            'kind' => $this->previewKind($mime),
            'url' => $this->mediaOpenUrl((int) $media->id),
            'download_url' => $this->mediaDownloadUrl((int) $media->id),
            'size' => $this->formatBytes((int) $media->size),
            'icon' => MimeIcon::forMedia($media),
            'position' => $position === false ? 0 : $position + 1,
            'total' => count($ids),
        ];
    }

    public function closePreview(): void
    {
        $this->previewItem = null;
    }

    /**
     * Steps to the previous or next file of the current listing window.
     */
    public function previewShift(int $direction): void
    {
        if ($this->previewItem === null) {
            return;
        }

        $ids = $this->listing()['files']->map(fn (Media $file): int => (int) $file->id)->all();
        $index = array_search((int) $this->previewItem['id'], $ids, true);

        if ($index === false) {
            return;
        }

        $target = $index + ($direction >= 0 ? 1 : -1);

        if (! isset($ids[$target])) {
            return;
        }

        $this->preview($ids[$target]);
    }

    protected function previewKind(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf', str_starts_with($mime, 'text/') => 'frame',
            default => 'other',
        };
    }

    public function deleteTarget(?string $type = null, ?int $id = null): void
    {
        if ($type === 'folder' && $id) {
            $this->selectedFolders = [$id];
            $this->selectedFiles = [];
        } elseif ($type === 'file' && $id) {
            $this->selectedFiles = [$id];
            $this->selectedFolders = [];
        }

        $this->deleteItems();
    }

    public function openFolder(int $folderId): void
    {
        $this->navigateToFolder($folderId);
    }

    public function selectFolder(int $folderId, bool $multi = false): void
    {
        if (! $multi) {
            $this->selectedFolders = [$folderId];
            $this->selectedFiles = [];

            return;
        }

        if (in_array($folderId, $this->selectedFolders, true) || in_array($folderId, $this->selectedFolders)) {
            $this->selectedFolders = array_values(array_filter(
                $this->selectedFolders,
                fn ($id) => (int) $id !== $folderId
            ));

            return;
        }

        $this->selectedFolders[] = $folderId;
    }

    public function selectFile(int $fileId, bool $multi = false): void
    {
        if (! $multi) {
            $this->selectedFiles = [$fileId];
            $this->selectedFolders = [];

            return;
        }

        if (in_array($fileId, $this->selectedFiles, true) || in_array($fileId, $this->selectedFiles)) {
            $this->selectedFiles = array_values(array_filter(
                $this->selectedFiles,
                fn ($id) => (int) $id !== $fileId
            ));

            return;
        }

        $this->selectedFiles[] = $fileId;
    }

    public function showInfo(?string $type = null, ?int $id = null): void
    {
        abort_unless($this->ability('getInfo'), 403);

        if ($type === null && $id === null) {
            if (count($this->selectedFolders) === 1 && count($this->selectedFiles) === 0) {
                $this->showInfo('folder', (int) $this->selectedFolders[0]);

                return;
            }

            if (count($this->selectedFiles) === 1 && count($this->selectedFolders) === 0) {
                $this->showInfo('file', (int) $this->selectedFiles[0]);

                return;
            }

            if ((count($this->selectedFolders) + count($this->selectedFiles)) > 1) {
                $count = count($this->selectedFolders) + count($this->selectedFiles);
                $this->infoItem = [
                    'type' => 'multi',
                    'id' => null,
                    'name' => $count.' items selected',
                    'size' => '—',
                    'path' => $this->folderPathString($this->currentFolder),
                    'mime' => count($this->selectedFolders).' folders · '.count($this->selectedFiles).' files',
                    'permissions' => '—',
                    'created' => '—',
                    'updated' => '—',
                    'extra' => null,
                ];
                $this->showInfoModal = true;

                return;
            }
        }

        if ($type === 'folder' && $id) {
            $folder = Folder::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $size = $this->folderSizeBytes($folder);
            $items = (int) $folder->children()->count() + (int) $folder->media()->where('collection_name', UploadRules::collection())->count();
            $this->infoItem = [
                'type' => 'folder',
                'id' => $folder->id,
                'name' => $folder->name,
                'size' => $this->formatBytes($size),
                'path' => $this->folderPathString($folder),
                'mime' => __('filament-file-explorer::file-explorer.folder_kind'),
                'permissions' => $this->folderPermissionLabel($folder),
                'created' => $folder->created_at?->format('Y/m/d H:i'),
                'updated' => $folder->updated_at?->format('Y/m/d H:i'),
                'extra' => trans_choice('filament-file-explorer::file-explorer.items_count', $items),
                'delete_note' => $this->folderDeleteNote($folder),
            ];
            $this->showInfoModal = true;

            return;
        }

        if ($type === 'file' && $id) {
            $media = Media::query()->findOrFail($id);
            $folder = $this->assertMediaUnderRoot($media);
            $deleteState = app(FileExplorerAuthorizer::class)->mediaDeleteState($this->scopeKey, $media);
            $this->infoItem = [
                'type' => 'file',
                'id' => $media->id,
                'name' => MediaLabel::display($media),
                'size' => $this->formatBytes((int) $media->size),
                'path' => $this->folderPathString($folder).' / '.$media->file_name,
                'mime' => $media->mime_type ?: '—',
                'permissions' => $this->mediaPermissionLabel($media),
                'created' => $media->created_at?->format('Y/m/d H:i'),
                'updated' => $media->updated_at?->format('Y/m/d H:i'),
                'extra' => $media->file_name,
                'icon' => MimeIcon::forMedia($media),
                'preview' => str_starts_with((string) $media->mime_type, 'image/')
                    ? $this->mediaOpenUrl($media->id)
                    : null,
                'delete_note' => $deleteState['reason'],
                'added_by' => app(Uploader::class)->label($media),
            ];
            $this->showInfoModal = true;

            return;
        }

        // Empty space / current folder
        $folder = $this->currentFolder;
        $this->assertUnderRoot($folder);
        $size = $this->folderSizeBytes($folder);
        $items = (int) $folder->children()->count() + (int) $folder->media()->where('collection_name', UploadRules::collection())->count();
        $this->infoItem = [
            'type' => 'folder',
            'id' => $folder->id,
            'name' => $folder->name,
            'size' => $this->formatBytes($size),
            'path' => $this->folderPathString($folder),
            'mime' => __('filament-file-explorer::file-explorer.current_folder_kind'),
            'permissions' => $this->folderPermissionLabel($folder),
            'created' => $folder->created_at?->format('Y/m/d H:i'),
            'updated' => $folder->updated_at?->format('Y/m/d H:i'),
            'extra' => trans_choice('filament-file-explorer::file-explorer.items_count', $items),
            'delete_note' => $this->folderDeleteNote($folder),
        ];
        $this->showInfoModal = true;
    }

    public function closeInfo(): void
    {
        $this->showInfoModal = false;
        $this->infoItem = null;
    }

    public function mediaOpenUrl(int $mediaId): string
    {
        return route('filament-file-explorer.media.show', ['scopeKey' => $this->scopeKey, 'media' => $mediaId]);
    }

    public function mediaDownloadUrl(int $mediaId): string
    {
        return route('filament-file-explorer.media.show', ['scopeKey' => $this->scopeKey, 'media' => $mediaId, 'download' => 1]);
    }

    public function mediaZipUrl(int $mediaId): string
    {
        return route('filament-file-explorer.media.zip-media', ['scopeKey' => $this->scopeKey, 'media' => $mediaId]);
    }

    public function folderZipUrl(int $folderId): string
    {
        return route('filament-file-explorer.media.zip-folder', ['scopeKey' => $this->scopeKey, 'folder' => $folderId]);
    }

    protected function folderPathString(Folder $folder): string
    {
        $parts = [];
        $node = $folder;
        $depth = 0;
        while ($node && $depth < 50) {
            array_unshift($parts, $node->name);
            if ((int) $node->id === $this->rootFolderId) {
                break;
            }
            $node = $node->parent;
            $depth++;
        }

        return implode(' / ', $parts);
    }

    protected function folderSizeBytes(Folder $folder): int
    {
        return (int) Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', (new Folder)->getMorphClass())
            ->whereIn('model_id', app(FolderTree::class)->descendantFolderIdsIncludingRoot((int) $folder->id))
            ->sum('size');
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1073741824, 2).' GB';
    }

    protected function mediaPermissionLabel(Media $media): string
    {
        $state = app(FileExplorerAuthorizer::class)->mediaDeleteState($this->scopeKey, $media);

        if ($state['allowed']) {
            if ($state['remaining_seconds'] !== null) {
                return __('filament-file-explorer::file-explorer.permissions.deletable_with_reason', ['reason' => $state['reason']]);
            }

            return __('filament-file-explorer::file-explorer.permissions.read_write_delete');
        }

        return __('filament-file-explorer::file-explorer.permissions.read_write').' · '.($state['reason'] ?? __('filament-file-explorer::file-explorer.delete_not_allowed'));
    }

    protected function folderPermissionLabel(Folder $folder): string
    {
        if ((int) $folder->id === $this->rootFolderId) {
            return __('filament-file-explorer::file-explorer.permissions.read_write_root_locked');
        }

        $state = app(FileExplorerAuthorizer::class)->folderDeleteState($this->scopeKey, $folder);

        if ($state['allowed']) {
            return __('filament-file-explorer::file-explorer.permissions.read_write_delete');
        }

        return __('filament-file-explorer::file-explorer.permissions.read_write').' · '.($state['reason'] ?? __('filament-file-explorer::file-explorer.permissions.folder_delete_denied'));
    }

    protected function folderDeleteNote(Folder $folder): ?string
    {
        $state = app(FileExplorerAuthorizer::class)->folderDeleteState($this->scopeKey, $folder);

        return $state['reason'];
    }

    /**
     * @return array{
     *     allowed: bool,
     *     reason_code: string|null,
     *     reason: string|null,
     *     remaining_seconds: int|null,
     *     window_seconds: int,
     *     hint: string
     * }
     */
    public function getDeleteState(string $type, ?int $id = null): array
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($docs->canAccess($this->scopeKey, $this->rootFolderId), 403);

        if ($type === 'file' && $id) {
            $media = Media::query()->findOrFail($id);
            $this->assertMediaUnderRoot($media);

            $state = $docs->mediaDeleteState($this->scopeKey, $media);
            $state['hint'] = $state['allowed']
                ? (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.permissions.deletable'))
                : (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.delete_not_allowed'));

            return $state;
        }

        if ($type === 'folder' && $id) {
            $folder = Folder::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $state = $docs->folderDeleteState($this->scopeKey, $folder);
            $state['hint'] = $state['allowed']
                ? __('filament-file-explorer::file-explorer.permissions.deletable')
                : (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.permissions.folder_delete_denied'));

            return $state;
        }

        return [
            'allowed' => false,
            'reason_code' => 'unknown',
            'reason' => __('filament-file-explorer::file-explorer.nothing_selected'),
            'remaining_seconds' => null,
            'window_seconds' => 0,
            'hint' => __('filament-file-explorer::file-explorer.nothing_selected'),
        ];
    }

    /**
     * Usage the sidebar shows, or null when the scope has no cap.
     *
     * @return array{used:int,limit:int,percent:int,used_label:string,limit_label:string,warning:bool,full:bool}|null
     */
    public function quotaState(): ?array
    {
        return app(Quota::class)->state($this->rootFolderId);
    }

    public function pageSize(): int
    {
        return max(1, (int) config('filament-file-explorer.listing.per_page', 100));
    }

    /**
     * The folders and files to render, sorted and windowed in SQL.
     *
     * @return array{folders: \Illuminate\Database\Eloquent\Collection<int, Folder>, files: \Illuminate\Database\Eloquent\Collection<int, Media>, shown: int, total: int, hasMore: bool}
     */
    public function listing(): array
    {
        // Not a public property: the render calls this once, but a host app's
        // view may not, and each call costs four queries.
        return $this->listingCache ??= (new FolderListing(
            $this->rootFolderId,
            (int) $this->currentFolder->id,
            $this->search,
            $this->sortBy,
            $this->sortDir,
        ))->window($this->perPage > 0 ? $this->perPage : $this->pageSize());
    }

    public function loadMore(): void
    {
        abort_unless($this->ability('browse'), 403);

        $this->perPage = ($this->perPage > 0 ? $this->perPage : $this->pageSize()) + $this->pageSize();
        $this->listingCache = null;
    }

    /**
     * Called whenever the listing's contents may have changed: navigation, a
     * new search, or a mutation. Sends the window back to the first page and
     * drops the memoised tree, which a create/move/delete in this same request
     * would otherwise leave describing the old structure.
     */
    public function resetListing(): void
    {
        $this->perPage = $this->pageSize();
        $this->listingCache = null;

        app(FolderTree::class)->flush();
        app(Quota::class)->flush();
    }

    /**
     * Called after a mutation: same as resetListing(), plus telling the other
     * explorers on the page. A navigation or a search calls resetListing()
     * instead — nothing changed for anyone else.
     */
    protected function afterMutation(): void
    {
        $this->resetListing();

        if ($this->announced) {
            return;
        }

        $this->announced = true;

        // A page can hold more than one explorer over the same tree — the
        // picker in a modal beside the page itself — and each keeps its own
        // copy of the listing. Never dispatched from refreshExplorer(), which
        // is what stops two components refreshing each other in a loop.
        $this->dispatch('fe-tree-changed', scopeKey: $this->scopeKey, origin: $this->getId());
    }

    #[On('fe-tree-changed')]
    public function onTreeChanged(string $scopeKey = '', string $origin = ''): void
    {
        if ($scopeKey !== $this->scopeKey || $origin === $this->getId()) {
            return;
        }

        $this->refreshExplorer();
    }

    /**
     * Re-reads what the render is built from, so a change made elsewhere — by
     * another user, or by another explorer on this page — shows up.
     *
     * Deliberately not resetListing(): that sends the window back to the first
     * page, which would undo a "load more" the user just asked for.
     */
    public function refreshExplorer(): void
    {
        if (! $this->ability('browse') || $this->isMidAction()) {
            return;
        }

        app(FolderTree::class)->flush();
        app(Quota::class)->flush();
        app(Uploader::class)->flush();
        $this->listingCache = null;

        $folder = $this->currentFolder === null
            ? null
            : Folder::query()->find($this->currentFolder->id);

        // Someone else may have deleted the folder being browsed. Falling back
        // to the root beats rendering a folder that is no longer there.
        if ($folder === null || ! app(FolderTree::class)->isUnderRoot($folder, $this->rootFolderId)) {
            $this->navigateToFolder($this->rootFolderId);

            return;
        }

        $this->currentFolder = $folder->fresh(['children', 'parent']);
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
    }

    /**
     * Whether the user is in the middle of something a refresh would interrupt.
     */
    protected function isMidAction(): bool
    {
        return $this->isCreatingNewFolder
            || $this->renamingType !== null
            || $this->deleteRequest !== null
            || $this->previewItem !== null
            || $this->files !== [];
    }

    /**
     * Seconds between automatic refreshes, or 0 when the panel did not ask for
     * any. Two users on the same root see nothing of each other otherwise.
     */
    public function refreshInterval(): int
    {
        return StandaloneSettings::refreshSeconds();
    }

    /**
     * Nested folder tree for the explorer sidebar — root is the primary folder.
     *
     * @return list<array{id:int,name:string,primary?:bool,children:list<array>}>
     */
    public function sidebarTree(): array
    {
        $ids = $this->descendantFolderIdsIncludingRoot();
        $folders = Folder::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $byParent = $folders->groupBy(fn (Folder $f) => (int) ($f->parent_id ?? 0));

        $build = function (int $parentId) use (&$build, $byParent): array {
            return ($byParent->get($parentId) ?? collect())
                ->map(fn (Folder $folder) => [
                    'id' => (int) $folder->id,
                    'name' => (string) $folder->name,
                    'children' => $build((int) $folder->id),
                ])
                ->values()
                ->all();
        };

        $root = $folders->firstWhere('id', $this->rootFolderId);

        if (! $root) {
            return [];
        }

        return [[
            'id' => (int) $root->id,
            'name' => (string) $root->name,
            'primary' => true,
            'children' => $build($this->rootFolderId),
        ]];
    }

    protected function descendantFolderIdsIncludingRoot(): array
    {
        return app(FolderTree::class)
            ->descendantFolderIdsIncludingRoot($this->rootFolderId);
    }

    public function createNewFolder(): void
    {
        abort_unless($this->ability('mkdir'), 403);
        $this->assertUnderRoot($this->currentFolder);

        $this->isCreatingNewFolder = true;
        $this->newFolderName = __('filament-file-explorer::file-explorer.folder_without_title');
        $this->dispatch('new-folder-created');
    }

    public function cancelNewFolder(): void
    {
        $this->isCreatingNewFolder = false;
        $this->newFolderName = '';
        $this->resetErrorBag('newFolderName');
    }

    public function saveNewFolder(): void
    {
        if (! $this->isCreatingNewFolder) {
            return;
        }

        abort_unless($this->ability('mkdir'), 403);
        $this->assertUnderRoot($this->currentFolder);

        $name = trim((string) $this->newFolderName);

        if ($name === '') {
            $this->cancelNewFolder();

            return;
        }

        $this->validate([
            'newFolderName' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) {
                    $slug = Str::slug(trim($value));
                    $existingFolder = Folder::query()
                        ->where('slug', $slug)
                        ->where('parent_id', $this->currentFolder?->id)
                        ->first();

                    if ($existingFolder) {
                        $fail(__('filament-file-explorer::file-explorer.folder_already_exists'));
                    }

                    $maxDepth = config('filament-file-explorer.folders.max_depth');

                    if ($maxDepth !== null && $this->currentFolder) {
                        if ($this->currentFolder->getDepth() >= $maxDepth - 1) {
                            $fail(__('filament-file-explorer::file-explorer.validation.max_folder_depth_exceeded', ['max' => $maxDepth]));
                        }
                    }
                },
            ],
        ]);

        $parent = $this->currentFolder;
        $newFolder = new Folder;
        $newFolder->name = $name;
        $slug = Str::slug($name);
        $newFolder->slug = $slug !== '' ? $slug : ('folder-'.Str::lower(Str::random(8)));
        $newFolder->parent_id = $parent->id;
        $newFolder->save();

        $this->newFolderName = '';
        $this->isCreatingNewFolder = false;
        $this->selectedFolders = [(int) $newFolder->id];
        $this->selectedFiles = [];
        $this->currentFolder = $parent->fresh(['children', 'parent']);
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        session([$this->sessionKey() => $parent->id]);
        $this->afterMutation();
        $this->dispatch('fe-folder-created', folderId: (int) $newFolder->id);
    }

    public function navigateToParent(): void
    {
        if ((int) $this->currentFolder->id === $this->rootFolderId) {
            return;
        }

        $this->search = '';
        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('fe-sel-cleared');

        if ($this->currentFolder->parent_id === null) {
            return;
        }

        $parentFolder = Folder::query()->find($this->currentFolder->parent_id);
        $this->assertUnderRoot($parentFolder);

        $this->currentFolder = $parentFolder;
        session([$this->sessionKey() => $parentFolder->id]);
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->pushNavHistory((int) $parentFolder->id);
        $this->resetListing();
    }

    public function navigateToFolder($folderId): void
    {
        $folder = Folder::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);

        $this->search = '';
        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('reset-folder');
        $this->dispatch('fe-sel-cleared');

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->resetListing();
        $this->pushNavHistory((int) $folder->id);

        session([$this->sessionKey() => $folder->id]);
    }

    public function navigateToBreadcrumb($breadcrumbIndex): void
    {
        $this->search = '';
        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('fe-sel-cleared');

        $this->breadcrumb = array_slice($this->breadcrumb, 0, $breadcrumbIndex + 1);
        $this->currentFolder = end($this->breadcrumb);
        $this->assertUnderRoot($this->currentFolder);

        session([$this->sessionKey() => $this->currentFolder->id]);
        $this->pushNavHistory((int) $this->currentFolder->id);
        $this->resetListing();
    }

    protected function generateBreadcrumb($folder)
    {
        $breadcrumb = [];

        while ($folder) {
            array_unshift($breadcrumb, $folder);

            if ((int) $folder->id === $this->rootFolderId) {
                break;
            }

            $folder = $folder->parent;
        }

        return $breadcrumb;
    }

    public function updatedFiles(): void
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($this->ability('upload'), 403);

        $targetId = $this->uploadTargetFolderId ?? (int) $this->currentFolder->id;
        $this->uploadTargetFolderId = null;

        $target = Folder::query()->findOrFail($targetId);
        $this->assertUnderRoot($target);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('files');
            $this->fileInputKey++;
            $this->dispatch('fe-upload-settled');

            Notification::make()
                ->danger()
                ->title(__('filament-file-explorer::file-explorer.upload_invalid'))
                ->body(collect($e->validator->errors()->all())->first() ?: __('filament-file-explorer::file-explorer.validation.file_not_allowed'))
                ->send();

            throw $e;
        }

        $actor = auth()->user();
        $uploaded = 0;
        $replaced = 0;
        $skipped = [];

        $paths = $this->uploadRelativePaths;
        $this->uploadRelativePaths = [];
        $this->uploadCreatedFolders = 0;
        $this->quotaRefused = 0;

        foreach ($this->files as $index => $file) {
            if (! $file) {
                continue;
            }

            // A folder upload sends one relative path per file; anything else
            // lands directly in the target folder.
            $folder = $this->resolveUploadFolder($target, $paths[$index] ?? null);

            $original = method_exists($file, 'getClientOriginalName')
                ? $file->getClientOriginalName()
                : (string) $file;

            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION) ?: 'bin');
            $name = pathinfo($original, PATHINFO_FILENAME);
            $safe = strtolower((Str::slug($name) ?: 'file').'.'.$extension);
            $label = $original;

            $clash = FileNames::existing($folder, $safe);
            $replacing = null;

            if ($clash !== null) {
                $policy = UploadRules::onConflict();

                if ($policy === 'skip') {
                    $skipped[] = $original;

                    continue;
                }

                if ($policy === 'replace') {
                    $this->assertMediaUnderRoot($clash);
                    $replacing = $clash;
                } else {
                    // Keep both, the way a desktop does: the stored name gets a
                    // slug suffix, the label the reader sees gets " (2)".
                    $copy = FileNames::availableIndex($folder, $safe);
                    $safe = FileNames::applyIndex($safe, $copy);
                    $label = FileNames::applyIndexToLabel($original, $copy);
                }
            }

            // Checked before anything is written, and after the conflict policy
            // is known: replacing a file frees what it took, so only the
            // difference counts against the quota.
            $incoming = (int) ($file->getSize() ?: 0) - (int) ($replacing->size ?? 0);

            if (! app(Quota::class)->reserve($this->rootFolderId, $incoming)) {
                $this->quotaRefused++;

                continue;
            }

            if ($replacing !== null) {
                $replacing->delete();
                $replaced++;
            }

            $folder
                ->addMedia($file)
                ->usingName($label)
                ->usingFileName($safe)
                ->withCustomProperties([
                    'user_id' => $actor?->getAuthIdentifier(),
                    'uploaded_by_type' => $actor ? $actor::class : null,
                    'uploaded_by_id' => $actor?->getAuthIdentifier(),
                ])
                ->toMediaCollection(UploadRules::collection());

            $uploaded++;
        }

        $this->reset('files');
        $this->fileInputKey++;

        if ((int) $target->id === (int) $this->currentFolder->id) {
            $this->currentFolder = $target->fresh(['children', 'parent']);
        } else {
            $this->currentFolder = $this->currentFolder->fresh(['children', 'parent']);
        }

        $this->afterMutation();

        $this->dispatch('fe-upload-settled');

        if ($uploaded > 0) {
            Notification::make()
                ->success()
                ->title(__('filament-file-explorer::file-explorer.uploaded'))
                ->body(
                    trans_choice('filament-file-explorer::file-explorer.uploaded_to_folder', $uploaded, ['folder' => $target->name])
                    .($this->uploadCreatedFolders > 0 ? ' · '.trans_choice('filament-file-explorer::file-explorer.upload_created_folders', $this->uploadCreatedFolders) : '')
                    .($replaced > 0 ? ' · '.trans_choice('filament-file-explorer::file-explorer.upload_replaced', $replaced) : '')
                )
                ->send();
        }

        if ($skipped !== []) {
            Notification::make()
                ->warning()
                ->title(trans_choice('filament-file-explorer::file-explorer.upload_skipped', count($skipped)))
                ->body(implode(', ', array_slice($skipped, 0, 5)))
                ->send();
        }

        $this->notifyQuotaRefused();
    }

    /**
     * Reports what a quota turned away, once for the whole action rather than
     * once per file.
     */
    protected function notifyQuotaRefused(): void
    {
        if ($this->quotaRefused === 0) {
            return;
        }

        $count = $this->quotaRefused;
        $state = $this->quotaState();
        $this->quotaRefused = 0;

        Notification::make()
            ->danger()
            ->title(trans_choice('filament-file-explorer::file-explorer.quota.refused', $count))
            ->body($state === null ? null : __('filament-file-explorer::file-explorer.quota.usage', [
                'used' => $state['used_label'],
                'limit' => $state['limit_label'],
            ]))
            ->send();
    }

    /**
     * Folder an uploaded file belongs in, creating the chain a folder upload
     * describes.
     *
     * The relative path comes from the browser, so every segment is flattened
     * to a name: "../" must not walk out of the target folder, and the depth
     * limit still applies — files from deeper levels land in the deepest folder
     * allowed rather than being refused.
     */
    protected function resolveUploadFolder(Folder $target, ?string $relativePath): Folder
    {
        if (! is_string($relativePath) || ! str_contains($relativePath, '/')) {
            return $target;
        }

        abort_unless($this->ability('mkdir'), 403);

        $segments = array_values(array_filter(
            array_map(
                fn (string $segment): string => trim(str_replace(["\0", '\\'], '', $segment)),
                explode('/', str_replace('\\', '/', $relativePath)),
            ),
            fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        ));

        // The last segment is the file itself.
        array_pop($segments);

        $maxDepth = config('filament-file-explorer.folders.max_depth');
        $current = $target;

        foreach (array_slice($segments, 0, 20) as $segment) {
            if ($maxDepth !== null && $current->getDepth() >= $maxDepth - 1) {
                break;
            }

            $current = $this->findOrCreateChildFolder($current, $segment);
        }

        return $current;
    }

    protected function findOrCreateChildFolder(Folder $parent, string $name): Folder
    {
        $slug = Str::slug($name) ?: ('folder-'.Str::lower(Str::random(8)));

        $existing = Folder::query()
            ->where('parent_id', $parent->id)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            return $existing;
        }

        $folder = new Folder;
        $folder->name = Str::limit($name, 255, '');
        $folder->slug = $slug;
        $folder->parent_id = $parent->id;
        $folder->save();

        $this->uploadCreatedFolders++;

        return $folder;
    }

    public function prepareUploadToFolder(int $folderId): void
    {
        $folder = Folder::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);
        abort_unless($this->ability('upload'), 403);
        $this->uploadTargetFolderId = $folderId;
    }

    public function deleteSelected(array $folders = [], array $files = []): void
    {
        if ($folders !== [] || $files !== []) {
            $this->selectedFolders = array_values(array_map('intval', $folders));
            $this->selectedFiles = array_values(array_map('intval', $files));
        }

        $this->deleteItems();
    }

    public function deleteItems(): void
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($docs->canAccess($this->scopeKey, $this->rootFolderId), 403);

        $fileIds = array_values(array_unique(array_map('intval', (array) $this->selectedFiles)));
        $folderIds = array_values(array_unique(array_map('intval', (array) $this->selectedFolders)));

        $deleted = 0;

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertMediaUnderRoot($media);

            $state = $docs->mediaDeleteState($this->scopeKey, $media);

            if (! $state['allowed']) {
                Notification::make()
                    ->warning()
                    ->title(MediaLabel::display($media))
                    ->body($state['reason'] ?? __('filament-file-explorer::file-explorer.file_delete_denied'))
                    ->send();

                continue;
            }

            if (Trash::enabled()) {
                app(Trash::class)->trashMedia($media);
            } else {
                $media->delete();
            }

            $deleted++;
        }

        foreach ($folderIds as $folderId) {
            if ((int) $folderId === $this->rootFolderId) {
                Notification::make()
                    ->warning()
                    ->title(__('filament-file-explorer::file-explorer.root_not_deletable'))
                    ->send();

                continue;
            }

            $folder = Folder::query()->find($folderId);

            if (! $folder) {
                continue;
            }

            $this->assertUnderRoot($folder);
            $folderState = $docs->folderDeleteState($this->scopeKey, $folder);

            if (! $folderState['allowed']) {
                Notification::make()
                    ->warning()
                    ->title($folder->name)
                    ->body($folderState['reason'] ?? __('filament-file-explorer::file-explorer.folder_delete_denied'))
                    ->send();

                continue;
            }

            if (Trash::enabled()) {
                app(Trash::class)->trashFolder($folder);
            } else {
                $this->deleteFolderRecursive($folder);
            }

            $deleted++;
        }

        if ($deleted > 0) {
            Notification::make()
                ->success()
                ->title(match (true) {
                    Trash::enabled() => trans_choice('filament-file-explorer::file-explorer.trash.moved', $deleted),
                    $deleted === 1 => __('filament-file-explorer::file-explorer.item_deleted'),
                    default => __('filament-file-explorer::file-explorer.items_deleted', ['count' => $deleted]),
                })
                ->send();
        }

        $this->selectedFiles = [];
        $this->selectedFolders = [];
        $this->dispatch('reset-media');
        $this->dispatch('reset-folder');
        $this->dispatch('clear-all-selections');
        $this->dispatch('fe-sel-cleared');

        if (! Folder::query()->find($this->currentFolder->id)) {
            $this->currentFolder = Folder::with(['children', 'parent'])->findOrFail($this->rootFolderId);
            session([$this->sessionKey() => $this->rootFolderId]);
            $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        } else {
            $this->currentFolder = $this->currentFolder->fresh(['children', 'parent']);
        }

        $this->afterMutation();
    }

    /**
     * Permanent delete, used when the trash is off. forceDelete because the
     * model soft-deletes: delete() would leave rows behind that nothing ever
     * shows again and nothing ever purges.
     */
    protected function deleteFolderRecursive(Folder $folder): void
    {
        foreach ($folder->children as $child) {
            $this->deleteFolderRecursive($child);
        }

        foreach ($folder->getMedia(UploadRules::collection()) as $media) {
            $media->delete();
        }

        $folder->forceDelete();
    }

    public function moveItemsToFolder($targetFolderId, $folderIds = [], $fileIds = []): void
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($this->ability('move'), 403);

        $targetFolder = Folder::query()->find($targetFolderId);

        if (! $targetFolder) {
            return;
        }

        $this->assertUnderRoot($targetFolder);

        $folderIds = array_values(array_unique(array_map('intval', (array) $folderIds)));
        $fileIds = array_values(array_unique(array_map('intval', (array) $fileIds)));

        foreach ($folderIds as $folderId) {
            if ((int) $folderId === $this->rootFolderId) {
                continue;
            }

            if ((int) $folderId === (int) $targetFolderId || $this->folderIsAncestorOf((int) $folderId, (int) $targetFolderId)) {
                continue;
            }

            $folder = Folder::query()->find($folderId);

            if (! $folder) {
                continue;
            }

            $this->assertUnderRoot($folder);
            $folder->parent_id = $targetFolderId;
            $folder->save();
        }

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertMediaUnderRoot($media);

            $media->model_id = $targetFolderId;
            $media->save();
        }

        $this->selectedFolders = [];
        $this->selectedFiles = [];
        $this->dispatch('reset-media');
        $this->dispatch('reset-folder');
        $this->dispatch('fe-sel-cleared');
        $this->currentFolder = $this->currentFolder->fresh(['children']);
        $this->afterMutation();
    }

    public function copyItemsToFolder($targetFolderId, $folderIds = [], $fileIds = []): void
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($this->ability('copy'), 403);

        $this->quotaRefused = 0;

        $targetFolder = Folder::query()->find($targetFolderId);

        if (! $targetFolder) {
            return;
        }

        $this->assertUnderRoot($targetFolder);

        $folderIds = array_values(array_unique(array_map('intval', (array) $folderIds)));
        $fileIds = array_values(array_unique(array_map('intval', (array) $fileIds)));

        foreach ($folderIds as $folderId) {
            if ((int) $folderId === $this->rootFolderId) {
                continue;
            }

            if ((int) $folderId === (int) $targetFolderId || $this->folderIsAncestorOf((int) $folderId, (int) $targetFolderId)) {
                continue;
            }

            $source = Folder::query()->find($folderId);

            if (! $source) {
                continue;
            }

            $this->assertUnderRoot($source);
            $this->copyFolderRecursive($source, $targetFolder);
        }

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertMediaUnderRoot($media);

            $this->duplicateMedia($media, $targetFolder);
        }

        $this->dispatch('fe-sel-cleared');
        $this->currentFolder = $this->currentFolder->fresh(['children']);
        $this->afterMutation();
        Notification::make()->success()->title(__('filament-file-explorer::file-explorer.copied'))->send();
        $this->notifyQuotaRefused();
    }

    /**
     * True when $ancestorId is the same as or an ancestor of $descendantId.
     */
    protected function folderIsAncestorOf(int $ancestorId, int $descendantId): bool
    {
        if ($ancestorId === $descendantId) {
            return true;
        }

        $folder = Folder::query()->find($descendantId);
        $depth = 0;

        while ($folder && $folder->parent_id && $depth < 50) {
            if ((int) $folder->parent_id === $ancestorId) {
                return true;
            }

            $folder = Folder::query()->find($folder->parent_id);
            $depth++;
        }

        return false;
    }


    /**
     * @return array{scopeKey: string, rootFolderId: int, routePrefix: string}
     */
    public function urlConfig(): array
    {
        return [
            'scopeKey' => $this->scopeKey,
            'rootFolderId' => $this->rootFolderId,
            'routePrefix' => route('filament-file-explorer.media.show', ['scopeKey' => $this->scopeKey, 'media' => 0]),
        ];
    }

    public function render()
    {
        return view('filament-file-explorer::livewire.file-explorer');
    }
}
