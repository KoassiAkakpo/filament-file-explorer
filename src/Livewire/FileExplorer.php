<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Events\FileAnnotated;
use Koassi\FilamentFileExplorer\Events\FileCopied;
use Koassi\FilamentFileExplorer\Events\FileDeleted;
use Koassi\FilamentFileExplorer\Events\FileMoved;
use Koassi\FilamentFileExplorer\Events\FileRenamed;
use Koassi\FilamentFileExplorer\Events\FileRestored;
use Koassi\FilamentFileExplorer\Events\FileShared;
use Koassi\FilamentFileExplorer\Events\FileTrashed;
use Koassi\FilamentFileExplorer\Events\FileUploaded;
use Koassi\FilamentFileExplorer\Events\FolderAnnotated;
use Koassi\FilamentFileExplorer\Events\FolderCopied;
use Koassi\FilamentFileExplorer\Events\FolderCreated;
use Koassi\FilamentFileExplorer\Events\FolderDeleted;
use Koassi\FilamentFileExplorer\Events\FolderMoved;
use Koassi\FilamentFileExplorer\Events\FolderRenamed;
use Koassi\FilamentFileExplorer\Events\FolderRestored;
use Koassi\FilamentFileExplorer\Events\FolderTrashed;
use Koassi\FilamentFileExplorer\Events\ShareRevoked;
use Koassi\FilamentFileExplorer\Exceptions\MissingRootFolder;
use Koassi\FilamentFileExplorer\Models\FileShare;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\ActiveRoot;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FileKinds;
use Koassi\FilamentFileExplorer\Support\FileNames;
use Koassi\FilamentFileExplorer\Support\FolderListing;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\MediaLabel;
use Koassi\FilamentFileExplorer\Support\MediaScope;
use Koassi\FilamentFileExplorer\Support\MimeIcon;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\Sharing;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FileExplorer extends Component
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

    /**
     * Narrows the listing to one kind of file.
     *
     * Not remembered across mounts, unlike the view mode and the sort: those are
     * how you like to look at a library, a filter is what you are looking for
     * right now. Coming back tomorrow to a folder that seems to hold three files
     * would be a bug report, not a convenience.
     */
    public ?string $kind = null;

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

    /** @var array{folders: Collection<int, Folder>, files: Collection<int, Media>, shown: int, total: int, hasMore: bool}|null */
    private ?array $listingCache = null;

    /**
     * Flushed wherever the listing is: the panes are listings, so anything that
     * makes one stale makes them all stale.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $columnPanesCache = null;

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    public ?string $renamingType = null;

    public ?int $renamingId = null;

    public string $renameValue = '';

    /** @var array{type:?string,id:?int,name:?string,size:?string,path:?string,mime:?string,permissions:?string,created:?string,updated:?string,extra:?string,preview:?string,icon?:string}|null */
    public ?array $infoItem = null;

    public bool $showInfoModal = false;

    /**
     * The annotation the inspector is editing, mirrored here so the textarea and
     * the tag list survive the round trip that saves them.
     *
     * Seeded by showInfo() and cleared with the panel. Kept beside $infoItem
     * rather than inside it because these two are the only bound inputs in the
     * panel: everything else there is read-only text the server owns.
     */
    public string $description = '';

    /** @var list<array{id: int, name: string, color: string|null}> */
    public array $tags = [];

    public string $tagInput = '';

    /**
     * Non-empty when the explorer was mounted as a form field's picker: the
     * token identifies the field, and travels back out on `fe-picked`.
     *
     * The field's state and the explorer's selection are deliberately *not* the
     * same thing. `setSelection()` narrows to the listing, so a chosen file
     * whose folder is not on screen would be dropped on the next sync — which is
     * why the modal does not reopen with the field's value selected. The value
     * lives on the field; the selection is what is on screen now.
     */
    public string $pickerToken = '';

    public bool $pickMultiple = false;

    /**
     * The kinds a picker will accept, or empty for any.
     *
     * Locked, because it is a restriction: a public Livewire property is the
     * client's own state, and a limit the client can empty is not a limit. The
     * field sets it at mount and nothing changes it after.
     *
     * @var list<string>
     */
    #[Locked]
    public array $pickKinds = [];

    /**
     * The colour the next tag is added with, or null for no opinion.
     *
     * Null is deliberately not "grey": grey is a choice that recolours the word
     * everywhere it appears, while null leaves an existing word's colour alone
     * and gives a new one the neutral dot. Deferred like $tagInput — it travels
     * with the addTag request rather than costing a round trip per swatch.
     */
    public ?string $tagColor = null;

    /**
     * The tag the listing is narrowed to, or null. Validated against the scope
     * on every read, so an id from another scope narrows nothing.
     */
    public ?int $tagId = null;

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

    /**
     * The share panel's state, on the server like the other dialogs: it has to
     * report an expiry and a view count, which Alpine has no way to know.
     *
     * @var array<string, mixed>|null
     */
    public ?array $shareItem = null;

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

        // Normalised once, here, so a field naming a kind that does not exist
        // restricts nothing rather than everything — and so the value the Locked
        // attribute then holds to is the clean one.
        $this->pickKinds = FileKinds::normaliseMany($this->pickKinds);

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

        $folder = FolderModel::query()->with(['children', 'parent'])->find($currentId);

        if (! $folder || ! app(FolderTree::class)->isUnderRoot($folder, $this->rootFolderId)) {
            $folder = FolderModel::query()->with(['children', 'parent'])->find($this->rootFolderId);

            // Not findOrFail: that answered a root the host never configured
            // with a 404, which reads as the page being missing rather than the
            // field being unset. See Exceptions\MissingRootFolder.
            if (! $folder) {
                throw MissingRootFolder::for($this->scopeKey, $this->rootFolderId);
            }

            session([$sessionKey => $folder->id]);
        }

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->navHistory = [(int) $folder->id];
        $this->navIndex = 0;
        $this->perPage = $this->pageSize();
    }

    /**
     * Namespaced by root as well as by scope: a scope may offer several roots,
     * and the folder remembered in one of them is not a folder of another —
     * containment would reject it and send the user back to the root anyway.
     */
    protected function sessionKey(): string
    {
        return 'currentFolderId.'.$this->scopeKey.'.'.$this->rootFolderId;
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
        $this->viewMode = StandaloneSettings::defaultViewMode();

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

    /**
     * Also per root: pasting into another root would fail the containment check
     * with a 403 rather than a shrug.
     */
    protected function clipboardKey(): string
    {
        return 'clipboard.'.$this->scopeKey.'.'.$this->rootFolderId;
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
        $folder = app(MediaScope::class)->folderUnderRoot($media, $this->rootFolderId);

        // Aborts when the row is not one of the explorer's, or its folder is
        // missing or out of scope, so what comes back is always the media's
        // in-scope folder.
        abort_if($folder === null, 403);

        return $folder;
    }

    public function setViewMode(string $mode): void
    {
        if ($mode === 'icons') {
            $mode = 'grid';
        }

        $this->viewMode = in_array($mode, StandaloneSettings::VIEW_MODES, true)
            ? $mode
            : StandaloneSettings::defaultViewMode();

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
        return app(Abilities::class)->all($this->scopeKey, $this->rootFolderId);
    }

    protected function authorizer(): FileExplorerAuthorizer
    {
        return app(FileExplorerAuthorizer::class);
    }

    protected function ability(string $key): bool
    {
        return app(Abilities::class)->allows($this->scopeKey, $this->rootFolderId, $key);
    }

    protected function rules(): array
    {
        return [
            'files.*' => [
                'file',
                'max:'.UploadRules::maxSizeKb(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
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

        $this->forgetSelection();
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
        $folder = FolderModel::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);

        $this->showTrash = false;
        $this->search = '';
        $this->forgetSelection();
        $this->dispatch('reset-folder');

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->resetListing();
        session([$this->sessionKey() => $folder->id]);
    }

    public function setSort(string $by, ?string $dir = null): void
    {
        if (! in_array($by, FolderListing::SORTS, true)) {
            return;
        }

        $this->listingCache = null;
        $this->columnPanesCache = null;

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
        // The browser's mirror, and the only caller that gets narrowed: it is
        // the one that can be describing a folder the component has left.
        [$folderIds, $fileIds] = $this->narrowToListing(
            array_values(array_map('intval', (array) $folders)),
            array_values(array_map('intval', (array) $files)),
        );

        $this->replaceSelection($folderIds, $fileIds);
    }

    /**
     * Sets the selection to exactly these ids.
     *
     * The server's own setter, for the callers that name what they mean rather
     * than mirror a browser: a delete requested on the current folder, or on the
     * root, is legitimate and neither appears in its own listing.
     *
     * @param  list<int>  $folderIds
     * @param  list<int>  $fileIds
     */
    protected function replaceSelection(array $folderIds, array $fileIds): void
    {
        $this->selectedFolders = $folderIds;
        $this->selectedFiles = $fileIds;

        $this->refreshInfo();
    }

    /**
     * Keeps only what the folder on screen is actually showing.
     *
     * The browser mirrors its selection here through a debounced call, so a
     * request can arrive after the component has moved on — and then it names
     * items of the folder just left. That is what made a double-click report
     * "1 selected" over a folder absent from its own listing: the sync went out
     * forty milliseconds behind the navigation and the watch read it back as a
     * real selection.
     *
     * `enterFolder()` in the JS drops the pending sync on the one path where the
     * race was guaranteed. This is the guard for the paths nobody has found yet,
     * and for the ones that only happen on a slow connection — a selection of
     * something the listing does not show is not a selection.
     *
     * It costs nothing: the render computes the listing anyway, and this reads
     * the memoised copy.
     *
     * Deliberately not applied to selectFolder() / selectFile(), which are the
     * public API: an application selecting an item it is about to navigate to
     * means it, and it is not mirroring a browser that may be behind.
     *
     * @param  list<int>  $folderIds
     * @param  list<int>  $fileIds
     * @return array{list<int>, list<int>}
     */
    protected function narrowToListing(array $folderIds, array $fileIds): array
    {
        if (($folderIds === [] && $fileIds === []) || $this->currentFolder === null) {
            return [[], []];
        }

        $listing = $this->listing();

        return [
            array_values(array_intersect(
                $folderIds,
                $listing['folders']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            )),
            array_values(array_intersect(
                $fileIds,
                $listing['files']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            )),
        ];
    }

    public function clearSelection(): void
    {
        $this->selectedFolders = [];
        $this->selectedFiles = [];

        $this->refreshInfo();
    }

    /**
     * Clears the selection on the component's own initiative.
     *
     * clearSelection() is the browser asking; this is the server deciding — a
     * navigation, a search, the trash, a delete. The only difference is the
     * announcement: the browser has to be told to drop its own copy, which it
     * does not need when it was the one to ask.
     *
     * It exists because nine places used to empty the two properties themselves
     * and dispatch the event, and every one of them therefore skipped
     * refreshInfo() — the single hook that keeps the inspector on the selection.
     * That is how the panel came to go on describing a file the trash view does
     * not show, and it was one bug waiting in each of the nine.
     */
    protected function forgetSelection(): void
    {
        $this->selectedFolders = [];
        $this->selectedFiles = [];

        $this->refreshInfo();
        $this->dispatch('fe-sel-cleared');
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
            $source = FolderModel::query()->find($folderId);
            if (! $source || (int) $source->id === $this->rootFolderId) {
                continue;
            }
            $this->assertUnderRoot($source);

            $copy = $this->copyFolderRecursive($source, $this->currentFolder);

            event(new FolderCopied($this->scopeKey, $this->rootFolderId, auth()->user(), $copy, $source));
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

        $fileName = FileNames::applyIndex((string) $media->file_name, $index);
        $copy = null;

        try {
            $copy = $target
                ->addMedia($path)
                ->preservingOriginal()
                ->usingName(FileNames::applyIndexToLabel((string) $media->name, $index))
                ->usingFileName($fileName)
                ->withCustomProperties(array_merge($media->custom_properties ?? [], [
                    'user_id' => $actor?->getAuthIdentifier(),
                    'uploaded_by_type' => $actor ? $actor::class : null,
                    'uploaded_by_id' => $actor?->getAuthIdentifier(),
                ]))
                ->toMediaCollection(UploadRules::collection());
        } catch (CouldNotLoadImage $e) {
            // As on upload: the copy exists, only its thumbnail does not.
            report($e);

            $copy = $this->mediaJustAdded($target, $fileName);
        }

        if ($copy instanceof Media) {
            event(new FileCopied($this->scopeKey, $this->rootFolderId, $actor, $copy, $media, $target));
        }

        return true;
    }

    protected function copyFolderRecursive(Folder $source, Folder $parent): Folder
    {
        $name = $source->name;
        $slug = Str::slug($name) ?: ('folder-'.Str::lower(Str::random(6)));
        $baseSlug = $slug;
        $i = 1;
        while (FolderModel::query()->where('parent_id', $parent->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $name = $source->name.' ('.$i.')';
            $i++;
        }

        $folder = FolderModel::new();
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
            $folder = FolderModel::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $this->renamingType = 'folder';
            $this->renamingId = $id;
            $this->renameValue = $folder->name;
            $this->dispatch('focus-rename-input', id: $this->getId());

            return;
        }

        if ($type === 'file' && $id) {
            $media = Media::query()->findOrFail($id);
            $this->assertMediaUnderRoot($media);
            $this->renamingType = 'file';
            $this->renamingId = $id;
            $this->renameValue = $media->name ?: pathinfo($media->file_name, PATHINFO_FILENAME);
            $this->dispatch('focus-rename-input', id: $this->getId());

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
            $folder = FolderModel::query()->findOrFail($this->renamingId);
            $this->assertUnderRoot($folder);
            $slug = Str::slug($name) ?: ('folder-'.Str::lower(Str::random(6)));
            $exists = FolderModel::query()
                ->where('parent_id', $folder->parent_id)
                ->where('slug', $slug)
                ->where('id', '!=', $folder->id)
                ->exists();
            if ($exists) {
                Notification::make()->danger()->title(__('filament-file-explorer::file-explorer.duplicate_folder'))->send();

                return;
            }
            $previousName = (string) $folder->name;
            $previousSlug = (string) $folder->slug;

            $folder->name = $name;
            $folder->slug = $slug;
            $folder->save();

            event(new FolderRenamed(
                $this->scopeKey,
                $this->rootFolderId,
                auth()->user(),
                $folder,
                $previousName,
                $previousSlug,
            ));
        } else {
            $media = Media::query()->findOrFail($this->renamingId);
            $this->assertMediaUnderRoot($media);

            $previousName = (string) $media->name;

            $media->name = $name;
            $media->save();

            event(new FileRenamed($this->scopeKey, $this->rootFolderId, auth()->user(), $media, $previousName));
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
            // Not setSelection(): a delete names its targets, and the current
            // folder and the root are both legitimate ones that a listing of
            // their own contents can never contain.
            $this->replaceSelection(
                array_values(array_map('intval', $folders)),
                array_values(array_map('intval', $files)),
            );
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
            $folder = FolderModel::query()->find($folderId);

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

        $this->forgetSelection();

        // And the panel goes even when it describes the current folder rather
        // than a selection in it: the trash is neither. This is the one thing
        // refreshInfo() will not do on its own, by design — a 'current' panel
        // stays put precisely because nothing about the folder changed, and
        // here the folder is simply not what is on screen any more.
        $this->closeInfo();
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
                    ? Carbon::parse($trashedAt)->format('Y/m/d H:i')
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

            $folder = FolderModel::query()->withTrashed()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $trash->restoreFolder($folder);

            event(new FolderRestored($this->scopeKey, $this->rootFolderId, auth()->user(), $folder));
        } else {
            abort_unless($this->ability('delete'), 403);

            $media = Media::query()->findOrFail($id);
            $this->assertTrashedMediaUnderRoot($media);
            $trash->restoreMedia($media);

            event(new FileRestored($this->scopeKey, $this->rootFolderId, auth()->user(), $media));
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

            $folder = FolderModel::query()->withTrashed()->find($folderId);

            if (! $folder || ! $folder->trashed()) {
                continue;
            }

            $this->assertUnderRoot($folder);

            $purgedId = (int) $folder->id;
            $purgedName = (string) $folder->name;
            $purgedParentId = $folder->parent_id === null ? null : (int) $folder->parent_id;

            $trash->purgeFolder($folder);
            $purged++;

            event(new FolderDeleted(
                $this->scopeKey,
                $this->rootFolderId,
                auth()->user(),
                $purgedId,
                $purgedName,
                $purgedParentId,
                purgedFromTrash: true,
            ));
        }

        foreach ($fileIds as $fileId) {
            abort_unless($this->ability('delete'), 403);

            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertTrashedMediaUnderRoot($media);

            $purgedMediaId = (int) $media->id;
            $purgedMediaName = (string) $media->name;
            $purgedFileName = (string) $media->file_name;
            $purgedFolderId = (int) $media->model_id;
            $purgedSize = (int) $media->size;

            $trash->purgeMedia($media);
            $purged++;

            event(new FileDeleted(
                $this->scopeKey,
                $this->rootFolderId,
                auth()->user(),
                $purgedMediaId,
                $purgedMediaName,
                $purgedFileName,
                $purgedFolderId,
                $purgedSize,
                purgedFromTrash: true,
            ));
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
        abort_unless($media->model_type === FolderModel::morphClass(), 403);
        abort_unless($media->collection_name === Trash::collection(), 403);

        $this->assertUnderRoot(FolderModel::query()->withTrashed()->find($media->model_id));
    }

    protected function trashOriginPath(?int $folderId): string
    {
        if ($folderId === null) {
            return '—';
        }

        $folder = FolderModel::query()->withTrashed()->find($folderId);

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
            ->where('model_type', FolderModel::morphClass())
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
     * Gives a file a public link, or hands back the one it already has.
     *
     * The ability is checked here and nowhere else afterwards: the share route
     * has no authenticated user to ask. Containment still runs on every request
     * to that route, against the root recorded on the row.
     */
    public function shareFile(int $mediaId): void
    {
        abort_unless(Sharing::enabled(), 404);
        abort_unless($this->ability('share'), 403);

        $media = Media::query()->findOrFail($mediaId);
        $this->assertMediaUnderRoot($media);

        $sharing = app(Sharing::class);

        // Asked before creating, because create() hands back an existing link
        // rather than minting a second one — and re-sharing something already
        // shared is not a new event.
        $existing = $sharing->activeForMedia((int) $media->id, $this->scopeKey, $this->rootFolderId);

        $share = $sharing->create(
            $this->scopeKey,
            $this->rootFolderId,
            $media,
            actor: auth()->user(),
        );

        if ($existing === null) {
            event(new FileShared($this->scopeKey, $this->rootFolderId, auth()->user(), $media, $share));
        }

        $this->shareItem = $this->shareState($share, $media);
    }

    /**
     * Withdraws the link the panel is showing.
     *
     * Takes the same ability that made it: someone who can hand a file out can
     * stop handing it out, and a separate ability would let an authorizer allow
     * the first without the second.
     */
    public function revokeShare(): void
    {
        abort_unless($this->ability('share'), 403);

        if ($this->shareItem === null) {
            return;
        }

        $share = FileShare::query()->find($this->shareItem['id'] ?? 0);

        // Nothing to say if it is already gone or was never this scope's: the
        // panel simply closes.
        if ($share instanceof FileShare
            && $share->scope_key === $this->scopeKey
            && $share->root_folder_id === $this->rootFolderId) {
            app(Sharing::class)->revoke($share);

            event(new ShareRevoked($this->scopeKey, $this->rootFolderId, auth()->user(), $share));
        }

        $this->shareItem = null;

        Notification::make()
            ->success()
            ->title(__('filament-file-explorer::file-explorer.share.revoked'))
            ->send();
    }

    public function closeShare(): void
    {
        $this->shareItem = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function shareState(FileShare $share, Media $media): array
    {
        return [
            'id' => (int) $share->id,
            'media_id' => (int) $media->id,
            'name' => MediaLabel::display($media),
            'url' => app(Sharing::class)->url($share),
            'expires_at' => $share->expires_at?->toDayDateTimeString(),
            'views' => (int) $share->views,
        ];
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

    /**
     * Nothing in the shipped UI calls this — selection is mirrored from the
     * Alpine store through setSelection — but it is public API a host app can
     * wire a button to, so the inspector has to follow it just the same.
     */
    /* ----------------------------------------------------------- picking */

    /**
     * Hands the selection to whoever mounted the explorer as a picker.
     *
     * Files only: a folder is where you look, not what you choose. The ids come
     * from `$selectedFiles`, which `setSelection()` has already narrowed to the
     * listing — but `selectFile()` is public API and is deliberately *not*
     * narrowed, so containment is proved again here rather than assumed. This is
     * the one place an id leaves the explorer for the host's own state.
     */
    public function pickSelected(): void
    {
        // Reading, not mutating: choosing a file is not downloading it.
        abort_unless($this->ability('browse'), 403);

        if (! $this->isPicking()) {
            return;
        }

        $ids = $this->pickableFileIds();

        if ($ids === []) {
            return;
        }

        // Single by default, and the *first* of the selection rather than a
        // refusal: a user who lassoed three files and pressed Choose meant to
        // choose, and an error message would be the field's way of blaming them
        // for its own arity.
        $this->dispatch(
            'fe-picked',
            token: $this->pickerToken,
            files: $this->pickMultiple ? $ids : [$ids[0]],
        );
    }

    public function isPicking(): bool
    {
        return $this->pickerToken !== '';
    }

    /**
     * The selected files that are really this scope's, in the order selected.
     *
     * @return list<int>
     */
    protected function pickableFileIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', $this->selectedFiles)));

        if ($ids === []) {
            return [];
        }

        $scope = app(MediaScope::class);

        // The restriction runs in SQL, against FileKinds' own predicate, rather
        // than as a mime test written out here: one owner, so what the listing
        // shows and what a pick accepts can never disagree.
        $allowed = FileKinds::applyAny(Media::query()->whereIn('id', $ids), $this->pickKinds)
            ->get()
            ->filter(fn (Media $media): bool => $scope->folderUnderRoot($media, $this->rootFolderId) !== null)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Intersected rather than returned as queried, so the order is the one
        // the user built rather than the one the database happened to answer in
        // — which is what decides the single case.
        return array_values(array_intersect($ids, $allowed));
    }

    public function selectFolder(int $folderId, bool $multi = false): void
    {
        $this->applyFolderSelection($folderId, $multi);
        $this->refreshInfo();
    }

    public function selectFile(int $fileId, bool $multi = false): void
    {
        $this->applyFileSelection($fileId, $multi);
        $this->refreshInfo();
    }

    protected function applyFolderSelection(int $folderId, bool $multi): void
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

    protected function applyFileSelection(int $fileId, bool $multi): void
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
                    'scope' => 'selection',
                    'id' => null,
                    'name' => trans_choice('filament-file-explorer::file-explorer.inspector.selected_items', $count),
                    'size' => '—',
                    'path' => $this->folderPathString($this->currentFolder),
                    'mime' => trans_choice('filament-file-explorer::file-explorer.inspector.folders_count', count($this->selectedFolders))
                        .' · '
                        .trans_choice('filament-file-explorer::file-explorer.inspector.files_count', count($this->selectedFiles)),
                    'permissions' => '—',
                    'created' => '—',
                    'updated' => '—',
                    'extra' => null,
                ];
                $this->loadAnnotation();
                $this->showInfoModal = true;

                return;
            }
        }

        if ($type === 'folder' && $id) {
            $folder = FolderModel::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $size = $this->folderSizeBytes($folder);
            $items = (int) $folder->children()->count() + (int) $folder->media()->where('collection_name', UploadRules::collection())->count();
            $this->infoItem = [
                'type' => 'folder',
                'scope' => 'selection',
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
            $this->loadAnnotation();
            $this->showInfoModal = true;

            return;
        }

        if ($type === 'file' && $id) {
            $media = Media::query()->findOrFail($id);
            $folder = $this->assertMediaUnderRoot($media);
            $deleteState = app(FileExplorerAuthorizer::class)->mediaDeleteState($this->scopeKey, $media);
            $this->infoItem = [
                'type' => 'file',
                'scope' => 'selection',
                'id' => $media->id,
                'name' => MediaLabel::display($media),
                'size' => $this->formatBytes((int) $media->size),
                'path' => $this->folderPathString($folder).' / '.$media->file_name,
                'mime' => MediaLabel::kind($media),
                'permissions' => $this->mediaPermissionLabel($media),
                'created' => $media->created_at?->format('Y/m/d H:i'),
                'updated' => $media->updated_at?->format('Y/m/d H:i'),
                'extra' => $media->file_name,
                'icon' => MimeIcon::forMedia($media),
                // The inspector shows it at 80px: the thumbnail is enough, and
                // the lightbox is what serves the original.
                'preview' => str_starts_with((string) $media->mime_type, 'image/')
                    ? $this->mediaThumbnailUrl($media->id)
                    : null,
                'delete_note' => $deleteState['reason'],
                'added_by' => app(Uploader::class)->label($media),
            ];
            $this->loadAnnotation();
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
            // Not 'selection': this panel describes the folder being browsed,
            // so it has no selection to follow and nothing to close with.
            'scope' => 'current',
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
        $this->loadAnnotation();
        $this->showInfoModal = true;
    }

    /**
     * Keeps the inspector on the selection: it re-reads when the selection
     * moves, and closes with the last selected item.
     *
     * Called from every entry point that changes the selection rather than from
     * the render, so a panel the user closed stays closed.
     */
    protected function refreshInfo(): void
    {
        if (! $this->showInfoModal || ($this->infoItem['scope'] ?? null) !== 'selection') {
            return;
        }

        if (($this->selectedFolders === [] && $this->selectedFiles === []) || ! $this->ability('getInfo')) {
            // Closing beats leaving the panel describing something the user no
            // longer has selected — and an ability revoked mid-session must not
            // turn an ordinary click into a 403.
            $this->closeInfo();

            return;
        }

        try {
            $this->showInfo();
        } catch (ModelNotFoundException) {
            // The selection can still name a row another session has deleted.
            $this->closeInfo();
        }
    }

    public function closeInfo(): void
    {
        $this->showInfoModal = false;
        $this->infoItem = null;
        $this->description = '';
        $this->tags = [];
        $this->resetTagInput();
    }

    /**
     * Normalised the moment it arrives, because it is a public property and so
     * the client's own state: anything that is not one of the palette's seven
     * names becomes null — no opinion — rather than reaching the write or the
     * rendered swatch.
     */
    public function updatedTagColor(): void
    {
        $this->tagColor = Annotations::colour($this->tagColor);
    }

    /**
     * The tag composer, emptied.
     *
     * One method rather than the pair written out at each of the four sites that
     * clear it: the name and the colour are one input between them, and the
     * package has already learned once what happens when a clear is spelled out
     * in several places — see forgetSelection().
     */
    protected function resetTagInput(): void
    {
        $this->tagInput = '';
        $this->tagColor = null;
    }

    /* ------------------------------------------------ tags and descriptions */

    public function annotationsEnabled(): bool
    {
        return Annotations::enabled();
    }

    public function canAnnotate(): bool
    {
        return Annotations::enabled() && $this->ability('annotate');
    }

    /**
     * The tag the listing is narrowed to, re-validated against the scope.
     *
     * Read rather than trusted, on every listing: $tagId is a public property, so
     * it arrives from the browser, and a tag of another scope must narrow
     * nothing rather than reach across. A tag that has since been dropped — its
     * last item untagged in another session — clears itself the same way.
     */
    protected function activeTagId(): ?int
    {
        if ($this->tagId === null || ! Annotations::enabled()) {
            return null;
        }

        $tag = app(Annotations::class)->tag($this->scopeKey, $this->tagId);

        if ($tag === null) {
            $this->tagId = null;

            return null;
        }

        return $tag['id'];
    }

    /**
     * @return array{id: int, name: string, color: string|null}|null
     */
    public function activeTag(): ?array
    {
        return app(Annotations::class)->tag($this->scopeKey, $this->activeTagId());
    }

    /**
     * The tags of everything the listing is about to draw, in two queries.
     *
     * The view asks per row; this is what stops that being a query per row. Both
     * kinds are prefetched together because a window holds folders and files and
     * the render walks them in one pass.
     *
     * @return array{folder: array<int, list<array{id: int, name: string, color: string|null}>>, file: array<int, list<array{id: int, name: string, color: string|null}>>}
     */
    public function tagIndex(): array
    {
        if (! Annotations::enabled()) {
            return ['folder' => [], 'file' => []];
        }

        $listing = $this->listing();
        $annotations = app(Annotations::class);

        return [
            'folder' => $annotations->tagsFor(
                Annotations::FOLDER,
                $listing['folders']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ),
            'file' => $annotations->tagsFor(
                Annotations::FILE,
                $listing['files']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ),
        ];
    }

    /**
     * @return list<array{id: int, name: string, color: string|null, count: int}>
     */
    public function availableTags(): array
    {
        if (! Annotations::enabled() || ! $this->ability('browse')) {
            return [];
        }

        return app(Annotations::class)->available($this->scopeKey);
    }

    /**
     * Narrows the listing to one tag, or clears the filter when handed the
     * active one again — a toggle, like the kind filter, so there is always a
     * way back without hunting for a separate "show all".
     */
    public function setTagFilter(?int $tagId): void
    {
        abort_unless($this->ability('browse'), 403);

        // Resolved before it is stored, so an id from another scope never
        // becomes the active filter even for one render.
        $tag = app(Annotations::class)->tag($this->scopeKey, $tagId);

        $this->tagId = ($tag === null || $this->tagId === $tag['id']) ? null : $tag['id'];

        $this->resetListing();
    }

    /**
     * The item the inspector is annotating, or null when there is nothing to
     * annotate: a multi-selection has no single description to write.
     *
     * @return array{type: string, id: int}|null
     */
    protected function annotationTarget(): ?array
    {
        $type = $this->infoItem['type'] ?? null;
        $id = $this->infoItem['id'] ?? null;

        if (! Annotations::isItemType($type) || ! is_int($id) || $id <= 0) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * Reads the annotation of whatever the panel is showing into the inputs.
     *
     * Called from showInfo(), so the inspector following the selection carries
     * the description and the tags with it rather than leaving one item's note
     * in the textarea of the next.
     */
    protected function loadAnnotation(): void
    {
        $target = $this->annotationTarget();

        if ($target === null || ! Annotations::enabled()) {
            $this->description = '';
            $this->tags = [];
            $this->resetTagInput();

            return;
        }

        $annotations = app(Annotations::class);

        $this->description = $annotations->description($target['type'], $target['id']);
        $this->tags = $annotations->tags($target['type'], $target['id']);
        $this->resetTagInput();
    }

    /**
     * Livewire's own hook, so the property is already set when the save runs.
     *
     * A `wire:blur` action beside `wire:model.blur` would race it: both travel
     * on the same event and nothing orders them, so the save could read the
     * value from before the edit.
     */
    public function updatedDescription(): void
    {
        $this->saveDescription();
    }

    public function saveDescription(): void
    {
        abort_unless($this->canAnnotate(), 403);

        $target = $this->annotationTarget();

        if ($target === null) {
            return;
        }

        $item = $this->resolveAnnotatable($target['type'], $target['id']);

        $previous = app(Annotations::class)->description($target['type'], $target['id']);

        $this->description = app(Annotations::class)
            ->setDescription($target['type'], $target['id'], $this->description);

        if ($this->description === $previous) {
            return;
        }

        $this->announceAnnotation($target['type'], $item, $previous, $this->tagNames());

        // A description is searchable, so writing one can change what a search
        // is showing — and it is a mutation the other explorer on the page has
        // no other way to hear about.
        $this->afterMutation();
    }

    public function addTag(): void
    {
        abort_unless($this->canAnnotate(), 403);

        $target = $this->annotationTarget();
        $name = trim($this->tagInput);

        if ($target === null || $name === '') {
            $this->resetTagInput();

            return;
        }

        $item = $this->resolveAnnotatable($target['type'], $target['id']);
        $before = $this->tagNames();

        $this->tags = app(Annotations::class)
            ->attach($this->scopeKey, $target['type'], $target['id'], $name, $this->tagColor);

        // Reset, so the colour is an opinion expressed once about one word. Kept
        // between adds it would recolour the *next* tag too — and tagging a blue
        // "Client" while red was still selected would turn it red everywhere.
        $this->resetTagInput();

        if ($this->tagNames() === $before) {
            return;
        }

        $this->announceAnnotation($target['type'], $item, $this->description, $before);
        $this->afterMutation();
    }

    public function removeTag(int $tagId): void
    {
        abort_unless($this->canAnnotate(), 403);

        $target = $this->annotationTarget();

        if ($target === null) {
            return;
        }

        $item = $this->resolveAnnotatable($target['type'], $target['id']);
        $before = $this->tagNames();

        $this->tags = app(Annotations::class)->detach($target['type'], $target['id'], $tagId);

        if ($this->tagNames() === $before) {
            return;
        }

        // The tag the listing was filtered by may have just lost its last item,
        // in which case it no longer exists — activeTagId() clears it on read.
        $this->announceAnnotation($target['type'], $item, $this->description, $before);
        $this->afterMutation();
    }

    /**
     * @return list<string>
     */
    protected function tagNames(): array
    {
        return array_values(array_map(fn (array $tag): string => $tag['name'], $this->tags));
    }

    /**
     * The row an annotation belongs to, with both guards run.
     *
     * Annotating goes through containment like every other action: the ids come
     * from the panel, and a description written on a folder outside the scope
     * would be a write past the only thing keeping scopes apart.
     */
    protected function resolveAnnotatable(string $type, int $id): Folder|Media
    {
        if ($type === Annotations::FOLDER) {
            $folder = FolderModel::query()->findOrFail($id);
            $this->assertUnderRoot($folder);

            return $folder;
        }

        $media = Media::query()->findOrFail($id);
        $this->assertMediaUnderRoot($media);

        return $media;
    }

    /**
     * @param  list<string>  $previousTags
     */
    protected function announceAnnotation(string $type, Folder|Media $item, string $previousDescription, array $previousTags): void
    {
        $actor = auth()->user();

        if ($type === Annotations::FOLDER && $item instanceof Folder) {
            event(new FolderAnnotated(
                $this->scopeKey,
                $this->rootFolderId,
                $actor,
                $item,
                $this->description,
                $this->tagNames(),
                $previousDescription,
                $previousTags,
            ));

            return;
        }

        if ($item instanceof Media) {
            event(new FileAnnotated(
                $this->scopeKey,
                $this->rootFolderId,
                $actor,
                $item,
                $this->description,
                $this->tagNames(),
                $previousDescription,
                $previousTags,
            ));
        }
    }

    public function mediaOpenUrl(int $mediaId): string
    {
        return route('filament-file-explorer.media.show', ['scopeKey' => $this->scopeKey, 'media' => $mediaId]);
    }

    /**
     * Thumbnail URL, through the same guarded route as everything else. The
     * controller falls back to the original when the conversion is missing, so
     * a library uploaded before thumbnails existed keeps rendering.
     */
    public function mediaThumbnailUrl(int $mediaId): string
    {
        return route('filament-file-explorer.media.show', [
            'scopeKey' => $this->scopeKey,
            'media' => $mediaId,
            'conversion' => 'thumbnail',
        ]);
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
            ->where('model_type', FolderModel::morphClass())
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

            // Only when there is something to say. An allowed delete with no
            // reason behind it used to be captioned "Deletable", which told the
            // reader nothing they had not just read on the button above it. A
            // reason given *while* allowing — a closing time window — is worth
            // showing, and a refusal always is.
            $state['hint'] = $state['allowed']
                ? (string) ($state['reason'] ?? '')
                : (string) ($state['reason'] ?? __('filament-file-explorer::file-explorer.delete_not_allowed'));

            return $state;
        }

        if ($type === 'folder' && $id) {
            $folder = FolderModel::query()->findOrFail($id);
            $this->assertUnderRoot($folder);
            $state = $docs->folderDeleteState($this->scopeKey, $folder);
            $state['hint'] = $state['allowed']
                ? (string) ($state['reason'] ?? '')
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
     * Roots the sidebar offers a switch between, empty when there is only one.
     *
     * @return list<array{id: int, name: string}>
     */
    public function rootOptions(): array
    {
        return ActiveRoot::options($this->scopeKey);
    }

    /**
     * Moves the whole explorer to another root of the same scope.
     *
     * The id is checked against the list the resolver offers, not merely
     * against the tree: every other action then compares containment with this
     * one root, so this is the only place a root can change and it must not
     * accept one from the request.
     */
    public function switchRoot(int $rootFolderId): void
    {
        abort_unless(ActiveRoot::offers($this->scopeKey, $rootFolderId), 403);
        abort_unless(app(FileExplorerAuthorizer::class)->canAccess($this->scopeKey, $rootFolderId), 403);

        if ($rootFolderId === $this->rootFolderId) {
            return;
        }

        $this->rootFolderId = $rootFolderId;

        ActiveRoot::choose($this->scopeKey, $rootFolderId);
        ScopeRoots::remember($this->scopeKey, $rootFolderId);

        // Each root remembers the folder it was left in, the way mount() does.
        $remembered = FolderModel::query()->with(['children', 'parent'])->find(session($this->sessionKey(), $rootFolderId));

        $folder = $remembered !== null && app(FolderTree::class)->isUnderRoot($remembered, $rootFolderId)
            ? $remembered
            : FolderModel::query()->with(['children', 'parent'])->findOrFail($rootFolderId);

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($folder);
        session([$this->sessionKey() => $folder->id]);

        // Nothing selected, held or navigated in the old root means anything
        // here.
        $this->showTrash = false;
        $this->forgetSelection();
        $this->search = '';
        $this->navHistory = [(int) $folder->id];
        $this->navIndex = 0;
        $this->clipboardReady = $this->hasClipboard();
        $this->closeInfo();
        $this->resetListing();
        $this->dispatch('fe-sel-cleared');
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
     * @return array{folders: Collection<int, Folder>, files: Collection<int, Media>, shown: int, total: int, hasMore: bool}
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
            $this->kind,
            $this->activeTagId(),
            $this->pickKinds,
        ))->window($this->perPage > 0 ? $this->perPage : $this->pageSize());
    }

    /**
     * One pane per level of the path: the Finder's column view.
     *
     * Built only for the mode that renders it, because it costs a listing per
     * level — `folders.max_depth` is what bounds that. Every pane is windowed in
     * SQL like the main listing: a pane over a folder holding thousands of files
     * is the same problem the listing already had to solve.
     *
     * Empty while searching. A search answers with matches from the whole scope,
     * which is not a path, so the view falls back to the flat result list rather
     * than pretending the two shapes are one.
     *
     * @return list<array<string, mixed>>
     */
    public function columnPanes(): array
    {
        // No panes while a tag filter is on, for the reason there are none while
        // searching: both answer from the subtree rather than from the folder,
        // and that is not a path. Panes would show the same deep item at every
        // level, each contradicting the one beside it. The view falls back to
        // the flat listing, as it already does for a search.
        if ($this->viewMode !== 'columns' || $this->search !== '' || $this->activeTagId() !== null || $this->currentFolder === null) {
            return [];
        }

        return $this->columnPanesCache ??= $this->buildColumnPanes();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildColumnPanes(): array
    {
        $path = array_values($this->breadcrumb);
        $limit = $this->pageSize();
        $panes = [];

        foreach ($path as $index => $folder) {
            $next = $path[$index + 1] ?? null;
            $isLast = $next === null;

            // The last pane is the folder being browsed, so it reuses the
            // listing the rest of the view is already built from — including
            // whatever a "load more" has widened it to.
            $listing = $isLast
                ? $this->listing()
                // The kind filter is a lens on the whole view, so the panes
                // behind the last one are narrowed too — a pane showing files
                // the filter excludes would contradict the one beside it. A tag
                // filter never reaches here: it answers from the subtree, and
                // the guard above drops the panes entirely for it.
                : (new FolderListing($this->rootFolderId, (int) $folder->id, '', $this->sortBy, $this->sortDir, $this->kind, $this->activeTagId(), $this->pickKinds))->window($limit);

            $panes[] = [
                'folder' => $folder,
                'folders' => $listing['folders'],
                'files' => $listing['files'],
                'activeFolderId' => $next === null ? null : (int) $next->id,
                'isLast' => $isLast,
                'hasMore' => (bool) $listing['hasMore'],
            ];
        }

        return $panes;
    }

    /**
     * Clicking an entry in a pane behind the last one.
     *
     * A folder moves the path to it, which drops the panes beyond. A file does
     * the same and then selects the file, which is what the Finder does when you
     * click into a column behind the one you are in.
     */
    public function revealInColumn(int $folderId, ?int $mediaId = null): void
    {
        $this->navigateToFolder($folderId);

        if ($mediaId !== null) {
            $this->selectFile($mediaId);
        }
    }

    /**
     * Right arrow in the column view: into the selected folder.
     *
     * Leaves the first entry of the column it opened selected, which is where
     * the Finder leaves you and what lets a further right arrow keep descending
     * without reaching for the mouse.
     */
    public function columnInto(int $folderId): void
    {
        abort_unless($this->ability('browse'), 403);

        $folder = FolderModel::query()->find($folderId);
        $this->assertUnderRoot($folder);

        $this->navigateToFolder($folderId);

        $listing = $this->listing();
        $firstFolder = $listing['folders']->first();

        if ($firstFolder !== null) {
            $this->selectFolder((int) $firstFolder->id);

            return;
        }

        $firstFile = $listing['files']->first();

        if ($firstFile !== null) {
            $this->selectFile((int) $firstFile->id);
        }
    }

    /**
     * Left arrow in the column view: out to the parent, with the folder just
     * left selected — so a further left arrow keeps walking up, and the pane you
     * came from stays marked.
     */
    public function columnBack(): void
    {
        abort_unless($this->ability('browse'), 403);

        if ($this->currentFolder === null || (int) $this->currentFolder->id === $this->rootFolderId) {
            return;
        }

        $leaving = (int) $this->currentFolder->id;

        $this->navigateToParent();
        $this->selectFolder($leaving);
    }

    /**
     * Narrows the listing, or clears the filter when handed the active kind
     * again — the menu entry is a toggle, so there is always a way back without
     * hunting for a separate "show all".
     */
    /**
     * The kinds the filter menu may offer.
     *
     * A picker's restriction narrows the menu as well as the listing: an entry
     * that can only ever come back empty is worse than no entry, because it
     * reads as a folder holding nothing rather than as a kind this field does
     * not take.
     *
     * @return list<string>
     */
    public function kindOptions(): array
    {
        return $this->pickKinds === [] ? FileKinds::all() : $this->pickKinds;
    }

    public function setKind(?string $kind): void
    {
        abort_unless($this->ability('browse'), 403);

        $kind = FileKinds::normalise($kind);

        // A kind the restriction excludes is not one this explorer filters by,
        // so it does nothing rather than emptying the folder.
        if ($kind !== null && $this->pickKinds !== [] && ! in_array($kind, $this->pickKinds, true)) {
            return;
        }

        $this->kind = $this->kind === $kind ? null : $kind;

        $this->resetListing();
    }

    public function loadMore(): void
    {
        abort_unless($this->ability('browse'), 403);

        $this->perPage = ($this->perPage > 0 ? $this->perPage : $this->pageSize()) + $this->pageSize();
        $this->listingCache = null;
        $this->columnPanesCache = null;
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
        $this->columnPanesCache = null;

        app(FolderTree::class)->flush();
        app(Quota::class)->flush();
        app(Annotations::class)->flush();
    }

    /**
     * Called after a mutation: same as resetListing(), plus telling the other
     * explorers on the page. A navigation or a search calls resetListing()
     * instead — nothing changed for anyone else.
     */
    /**
     * The media row an add() just wrote, when the object never came back.
     *
     * CouldNotLoadImage is thrown while generating the thumbnail, which runs
     * after the row and the original are written — the file is there. A
     * listener that never heard about it would leave a hole in an audit trail
     * exactly where an upload went half-wrong.
     */
    protected function mediaJustAdded(Folder $folder, string $fileName): ?Media
    {
        return Media::query()
            ->where('model_type', $folder->getMorphClass())
            ->where('model_id', $folder->id)
            ->where('collection_name', UploadRules::collection())
            ->where('file_name', $fileName)
            ->latest('id')
            ->first();
    }

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
        $this->columnPanesCache = null;

        $folder = $this->currentFolder === null
            ? null
            : FolderModel::query()->find($this->currentFolder->id);

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
        $folders = FolderModel::query()
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
        // Not a guard in the security sense — $showTrash is a public property, so
        // it is the client's own state and a check on it proves nothing. What it
        // prevents is a wedge: the inline name input renders inside the items
        // container, which the trash view does not render, so the component would
        // sit in "creating" with nothing on screen to type into or cancel.
        if ($this->showTrash) {
            return;
        }

        abort_unless($this->ability('mkdir'), 403);
        $this->assertUnderRoot($this->currentFolder);

        $this->isCreatingNewFolder = true;
        $this->newFolderName = __('filament-file-explorer::file-explorer.folder_without_title');
        $this->dispatch('new-folder-created', id: $this->getId());
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
                    $existingFolder = FolderModel::query()
                        ->where('slug', $slug)
                        ->where('parent_id', $this->currentFolder?->id)
                        ->first();

                    if ($existingFolder) {
                        $fail(__('filament-file-explorer::file-explorer.folder_already_exists'));
                    }

                    $maxDepth = StandaloneSettings::maxFolderDepth();

                    if ($maxDepth !== null && $this->currentFolder) {
                        if ($this->currentFolder->getDepth() >= $maxDepth - 1) {
                            $fail(__('filament-file-explorer::file-explorer.validation.max_folder_depth_exceeded', ['max' => $maxDepth]));
                        }
                    }
                },
            ],
        ]);

        $parent = $this->currentFolder;
        $newFolder = FolderModel::new();
        $newFolder->name = $name;
        $slug = Str::slug($name);
        $newFolder->slug = $slug !== '' ? $slug : ('folder-'.Str::lower(Str::random(8)));
        $newFolder->parent_id = $parent->id;
        $newFolder->save();

        event(new FolderCreated($this->scopeKey, $this->rootFolderId, auth()->user(), $newFolder));

        $this->newFolderName = '';
        $this->isCreatingNewFolder = false;
        $this->selectedFolders = [(int) $newFolder->id];
        $this->selectedFiles = [];

        // The other side of the same rule: this sets a selection rather than
        // clearing one, and the inspector follows the selection either way.
        $this->refreshInfo();

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

        $this->showTrash = false;
        $this->search = '';
        $this->forgetSelection();

        if ($this->currentFolder->parent_id === null) {
            return;
        }

        $parentFolder = FolderModel::query()->find($this->currentFolder->parent_id);
        $this->assertUnderRoot($parentFolder);

        $this->currentFolder = $parentFolder;
        session([$this->sessionKey() => $parentFolder->id]);
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->pushNavHistory((int) $parentFolder->id);
        $this->resetListing();
    }

    public function navigateToFolder($folderId): void
    {
        $folder = FolderModel::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);

        // A navigation says "show me this folder", and the trash is not a
        // folder — it is a view over the whole scope. Leaving it here is also
        // what makes it escapable the obvious way: the sidebar tree stays
        // clickable while the trash is up, and clicking a folder in it used to
        // change the folder underneath while the screen went on showing the
        // trash. The same line is in the four other places a navigation starts.
        $this->showTrash = false;
        $this->search = '';
        $this->forgetSelection();
        $this->dispatch('reset-folder');

        $this->currentFolder = $folder;
        $this->breadcrumb = $this->generateBreadcrumb($this->currentFolder);
        $this->resetListing();
        $this->pushNavHistory((int) $folder->id);

        session([$this->sessionKey() => $folder->id]);
    }

    public function navigateToBreadcrumb($breadcrumbIndex): void
    {
        $this->showTrash = false;
        $this->search = '';
        $this->forgetSelection();

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

        $target = FolderModel::query()->findOrFail($targetId);
        $this->assertUnderRoot($target);

        try {
            $this->validate();
        } catch (ValidationException $e) {
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
                $replacedId = (int) $replacing->id;
                $replacedName = (string) $replacing->name;
                $replacedFileName = (string) $replacing->file_name;
                $replacedSize = (int) $replacing->size;

                $replacing->delete();
                $replaced++;

                // Replacing destroys the old file outright, trash or no trash:
                // a listener has to hear that as a deletion of its own.
                event(new FileDeleted(
                    $this->scopeKey,
                    $this->rootFolderId,
                    $actor,
                    $replacedId,
                    $replacedName,
                    $replacedFileName,
                    (int) $folder->id,
                    $replacedSize,
                    purgedFromTrash: false,
                ));
            }

            $stored = null;

            try {
                $stored = $folder
                    ->addMedia($file)
                    ->usingName($label)
                    ->usingFileName($safe)
                    ->withCustomProperties([
                        'user_id' => $actor?->getAuthIdentifier(),
                        'uploaded_by_type' => $actor ? $actor::class : null,
                        'uploaded_by_id' => $actor?->getAuthIdentifier(),
                    ])
                    ->toMediaCollection(UploadRules::collection());
            } catch (CouldNotLoadImage $e) {
                // Only ever thrown while generating the thumbnail, which runs
                // after the row and the original are written — so the upload has
                // in fact succeeded. An image GD refuses to decode must not lose
                // it; the media route serves the original when the conversion is
                // missing.
                report($e);

                $stored = $this->mediaJustAdded($folder, $safe);
            }

            if ($stored instanceof Media) {
                event(new FileUploaded($this->scopeKey, $this->rootFolderId, $actor, $stored, $folder));
            }

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

        $maxDepth = StandaloneSettings::maxFolderDepth();
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

        $existing = FolderModel::query()
            ->where('parent_id', $parent->id)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            return $existing;
        }

        $folder = FolderModel::new();
        $folder->name = Str::limit($name, 255, '');
        $folder->slug = $slug;
        $folder->parent_id = $parent->id;
        $folder->save();

        $this->uploadCreatedFolders++;

        return $folder;
    }

    public function prepareUploadToFolder(int $folderId): void
    {
        $folder = FolderModel::query()->findOrFail($folderId);
        $this->assertUnderRoot($folder);
        abort_unless($this->ability('upload'), 403);
        $this->uploadTargetFolderId = $folderId;
    }

    public function deleteSelected(array $folders = [], array $files = []): void
    {
        if ($folders !== [] || $files !== []) {
            $this->replaceSelection(
                array_values(array_map('intval', $folders)),
                array_values(array_map('intval', $files)),
            );
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

                event(new FileTrashed($this->scopeKey, $this->rootFolderId, auth()->user(), $media, withFolder: false));
            } else {
                // Read before the delete: Media Library takes the row and the
                // bytes with it, and the size is only knowable now.
                $mediaId = (int) $media->id;
                $name = (string) $media->name;
                $fileName = (string) $media->file_name;
                $folderId = (int) $media->model_id;
                $size = (int) $media->size;

                $media->delete();

                event(new FileDeleted(
                    $this->scopeKey,
                    $this->rootFolderId,
                    auth()->user(),
                    $mediaId,
                    $name,
                    $fileName,
                    $folderId,
                    $size,
                    purgedFromTrash: false,
                ));
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

            $folder = FolderModel::query()->find($folderId);

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

                event(new FolderTrashed($this->scopeKey, $this->rootFolderId, auth()->user(), $folder));
            } else {
                $deletedId = (int) $folder->id;
                $deletedName = (string) $folder->name;
                $deletedParentId = $folder->parent_id === null ? null : (int) $folder->parent_id;

                $this->deleteFolderRecursive($folder);

                event(new FolderDeleted(
                    $this->scopeKey,
                    $this->rootFolderId,
                    auth()->user(),
                    $deletedId,
                    $deletedName,
                    $deletedParentId,
                    purgedFromTrash: false,
                ));
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

        $this->forgetSelection();
        $this->dispatch('reset-media');
        $this->dispatch('reset-folder');
        $this->dispatch('clear-all-selections');

        if (! FolderModel::query()->find($this->currentFolder->id)) {
            $this->currentFolder = FolderModel::query()->with(['children', 'parent'])->findOrFail($this->rootFolderId);
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

        $targetFolder = FolderModel::query()->find($targetFolderId);

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

            $folder = FolderModel::query()->find($folderId);

            if (! $folder) {
                continue;
            }

            $this->assertUnderRoot($folder);

            $fromParentId = $folder->parent_id === null ? null : (int) $folder->parent_id;

            $folder->parent_id = $targetFolderId;
            $folder->save();

            event(new FolderMoved(
                $this->scopeKey,
                $this->rootFolderId,
                auth()->user(),
                $folder,
                $fromParentId,
                (int) $targetFolderId,
            ));
        }

        foreach ($fileIds as $fileId) {
            $media = Media::query()->find($fileId);

            if (! $media) {
                continue;
            }

            $this->assertMediaUnderRoot($media);

            $fromFolderId = (int) $media->model_id;

            $media->model_id = $targetFolderId;
            $media->save();

            event(new FileMoved(
                $this->scopeKey,
                $this->rootFolderId,
                auth()->user(),
                $media,
                $fromFolderId,
                (int) $targetFolderId,
            ));
        }

        $this->forgetSelection();
        $this->dispatch('reset-media');
        $this->dispatch('reset-folder');
        $this->currentFolder = $this->currentFolder->fresh(['children']);
        $this->afterMutation();
    }

    public function copyItemsToFolder($targetFolderId, $folderIds = [], $fileIds = []): void
    {
        $docs = app(FileExplorerAuthorizer::class);

        abort_unless($this->ability('copy'), 403);

        $this->quotaRefused = 0;

        $targetFolder = FolderModel::query()->find($targetFolderId);

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

            $source = FolderModel::query()->find($folderId);

            if (! $source) {
                continue;
            }

            $this->assertUnderRoot($source);

            $copy = $this->copyFolderRecursive($source, $targetFolder);

            event(new FolderCopied($this->scopeKey, $this->rootFolderId, auth()->user(), $copy, $source));
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

        $folder = FolderModel::query()->find($descendantId);
        $depth = 0;

        while ($folder && $folder->parent_id && $depth < 50) {
            if ((int) $folder->parent_id === $ancestorId) {
                return true;
            }

            $folder = FolderModel::query()->find($folder->parent_id);
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
