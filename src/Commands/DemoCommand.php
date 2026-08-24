<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Commands\Concerns\GeneratesDemoFiles;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FileNames;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Fills a scope with a library worth looking at.
 *
 * Written for the demo, and it turns out to be what manual QA wanted too: every
 * feature the explorer has needs *content* to show itself — the kind filter
 * needs one file of each kind, the size sort needs sizes that differ, the
 * thumbnails need real images, the trash needs something in it. Producing that
 * by hand through the UI takes twenty minutes and gives a different library
 * every time.
 *
 * It writes through `addMedia()` on the folder, the same call an upload makes,
 * so the collection, the disk, the uploader properties and the thumbnail
 * conversion are the real ones rather than a second implementation that drifts.
 */
class DemoCommand extends Command
{
    use ConfirmableTrait;
    use GeneratesDemoFiles;

    protected $signature = 'file-explorer:demo
                            {--root= : Fill this folder id instead of the standalone root (needs --scope too)}
                            {--scope= : Scope key the tags belong to, when --root names a folder of another scope}
                            {--folders=14 : How many folders to create}
                            {--files=70 : How many files to create}
                            {--depth=3 : How deep the tree goes, capped by the configured max depth}
                            {--user= : Act as this user id, so per-user scopes resolve and "Added by" is filled in}
                            {--seed= : Reuse the seed a previous run printed to get the same library back}
                            {--fresh : Delete what the root already holds before filling it}
                            {--force : Skip the production confirmation}';

    protected $description = 'Fill a file explorer scope with demo folders and files';

    /**
     * Relative to storage_path(), and removed again before the command returns.
     */
    protected const WORKING_DIRECTORY = 'app/file-explorer-demo';

    /**
     * Folder names by the level they sit at, so the tree reads like something
     * somebody organised rather than folder-1 through folder-14.
     *
     * @var array<int, list<string>>
     */
    protected const FOLDER_NAMES = [
        0 => ['Contracts', 'Invoices', 'Photos', 'Reports', 'Marketing', 'Engineering', 'Onboarding', 'Archive 2025'],
        1 => ['Drafts', 'Signed', 'Q1', 'Q2', 'Q3', 'Q4', 'Assets', 'Exports', 'Meeting notes', 'Legal review'],
        2 => ['Approved', 'Superseded', 'Screenshots', 'Logos', 'Source', 'Reviewed', 'Sent'],
    ];

    /**
     * File titles per kind, so a spreadsheet is not called "Team photo".
     *
     * @var array<string, list<string>>
     */
    protected const FILE_TITLES = [
        'image' => ['Team photo', 'Office move', 'Product shot', 'Whiteboard', 'Site visit', 'Conference stand', 'Warehouse'],
        'pdf' => ['Service agreement', 'Annual report', 'Signed contract', 'Purchase order', 'Delivery note', 'Insurance policy'],
        'document' => ['Meeting notes', 'Handover', 'Scope of work', 'Release notes', 'Job description', 'Checklist'],
        'spreadsheet' => ['Budget forecast', 'Headcount', 'Invoice register', 'Stock take', 'Timesheet', 'Price list'],
        'presentation' => ['Quarterly review', 'Kickoff deck', 'Roadmap', 'Board update', 'Training'],
        'archive' => ['Brand assets', 'Legacy exports', 'Site backup', 'Photo batch'],
        'audio' => ['Standup recording', 'Client call', 'Voice note', 'Interview'],
        'video' => ['Product demo', 'Walkthrough', 'Customer story', 'Screen capture'],
    ];

    /**
     * The vocabulary the demo tags with, coloured from Annotations' own palette
     * so the filter menu has a closed list that means something.
     *
     * @var array<string, string>
     */
    protected const TAGS = [
        'Urgent' => 'red',
        'To review' => 'orange',
        'Approved' => 'green',
        'Client' => 'blue',
        'Internal' => 'purple',
        'Archived' => 'grey',
    ];

    public function handle(): int
    {
        // Writes rows and files, so it asks before running against production —
        // the same guard migrate:fresh and db:seed use, and the same --force.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $seed = $this->resolveSeed();

        // Seeded once here, and every random choice below draws from it: a seed
        // a previous run printed gives that library back, which is what makes a
        // screenshot or a bug report reproducible.
        mt_srand($seed);

        $actor = $this->resolveActor();

        if ($actor === false) {
            return self::FAILURE;
        }

        $scope = $this->resolveScope();

        if ($scope === null) {
            return self::FAILURE;
        }

        [$scopeKey, $root] = $scope;

        if ($this->option('fresh')) {
            $this->emptyRoot($root);
        }

        $this->components->info('Filling "'.$root->name.'" (#'.$root->id.') in scope "'.$scopeKey.'".');

        $folders = $this->createFolders($root);
        [$files, $bytes, $refused] = $this->createFiles($folders, $actor);

        $tagged = $this->annotate($scopeKey, $folders, $files);
        $trashed = $this->fillTrash($folders, $files);

        $this->report($seed, $folders, $files, $bytes, $refused, $tagged, $trashed, $scopeKey, $root);

        return self::SUCCESS;
    }

    protected function resolveSeed(): int
    {
        $given = $this->option('seed');

        return $given === null ? random_int(1, 999999) : max(1, (int) $given);
    }

    /**
     * The user the demo acts as, or false when --user names nobody.
     *
     * Logging in is not cosmetic: the per-user and per-tenant resolvers abort
     * without an authenticated user, so this is what lets the demo fill the
     * scope those resolvers hand out. It also fills the uploader properties, so
     * the inspector's "Added by" says something.
     */
    protected function resolveActor(): Authenticatable|false|null
    {
        $id = $this->option('user');

        if ($id === null) {
            return null;
        }

        $guard = Auth::guard();
        $user = method_exists($guard, 'getProvider') ? $guard->getProvider()?->retrieveById($id) : null;

        if ($user === null) {
            $this->components->error('No user with id '.$id.'.');

            return false;
        }

        Auth::login($user);

        return $user;
    }

    /**
     * Scope key and root folder to fill.
     *
     * @return array{string, Folder}|null
     */
    protected function resolveScope(): ?array
    {
        $rootOption = $this->option('root');

        if ($rootOption !== null) {
            return $this->resolveGivenRoot((int) $rootOption);
        }

        // Otherwise the standalone resolver's own, which is the library the
        // panel's explorer page actually opens.
        try {
            $resolver = app(FileExplorerRootResolver::class);
            $scopeKey = $resolver->scopeKey();
            $rootId = $resolver->rootFolderId();
        } catch (HttpExceptionInterface) {
            // The per-user and per-tenant resolvers abort without a user or a
            // tenant, and a console command has neither by default.
            $this->components->error('The configured resolver needs a request context.');
            $this->line('Pass --user=ID for a per-user scope, or name the folder outright:');
            $this->line('  php artisan file-explorer:demo --root=ID --scope=KEY');

            return null;
        }

        $root = FolderModel::query()->find($rootId);

        if (! $root instanceof Folder) {
            $this->components->error('The resolver returned folder #'.$rootId.', which does not exist.');

            return null;
        }

        // The panel's fluent settings are invisible from the console: a host that
        // called ->scopeKey() on the plugin rather than setting the config key
        // gets the config value here, and the demo would fill a root the panel
        // never opens. Saying which one it used is what makes that visible.
        $this->line('Resolved through '.$resolver::class.'.');

        return [$scopeKey, $root];
    }

    /**
     * @return array{string, Folder}|null
     */
    protected function resolveGivenRoot(int $rootId): ?array
    {
        $root = FolderModel::query()->find($rootId);

        if (! $root instanceof Folder) {
            $this->components->error('No folder with id '.$rootId.'.');

            return null;
        }

        $scopeKey = $this->option('scope');

        if ($scopeKey === null || (string) $scopeKey === '') {
            // Tags are a scope's own vocabulary, so a wrong key here would tag
            // everything into a list the explorer never offers. There is no
            // guessing it from a folder: a record-scoped page's key is built
            // from the model and the record.
            $this->components->error('--root needs --scope: tags belong to a scope, and a folder does not name one.');
            $this->line('Standalone default: --scope='.config('filament-file-explorer.standalone.scope_key', 'library'));
            $this->line('Record-scoped: --scope={model-kebab}.{id}, e.g. --scope=project.4');

            return null;
        }

        return [(string) $scopeKey, $root];
    }

    /**
     * Everything the root holds, deleted for real — trash included.
     *
     * forceDelete, not delete: the folder model soft-deletes, so a demo re-run
     * would otherwise pile up rows in a trash nobody asked to fill.
     */
    protected function emptyRoot(Folder $root): void
    {
        $ids = FolderModel::query()->withTrashed()->where('parent_id', $root->id)->pluck('id');

        Media::query()
            ->where('model_type', FolderModel::morphClass())
            ->whereIn('model_id', $this->descendantIds($root))
            ->get()
            ->each(fn (Media $media) => $media->forceDelete());

        FolderModel::query()->withTrashed()->whereIn('id', $ids)->get()
            ->each(fn (Folder $folder) => $this->purgeFolder($folder));

        // Both memoise for the request, and this one *is* a request: the byte
        // total and the containment answers just changed under them.
        app(Quota::class)->flush();

        $this->components->info('Emptied "'.$root->name.'".');
    }

    protected function purgeFolder(Folder $folder): void
    {
        FolderModel::query()->withTrashed()->where('parent_id', $folder->id)->get()
            ->each(fn (Folder $child) => $this->purgeFolder($child));

        $folder->forceDelete();
    }

    /**
     * @return list<int>
     */
    protected function descendantIds(Folder $root): array
    {
        $ids = [(int) $root->id];
        $frontier = [(int) $root->id];

        while ($frontier !== []) {
            $frontier = FolderModel::query()->withTrashed()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * The tree, breadth-first so a level fills before the next one starts.
     *
     * The root counts as a folder files can land in, and the depth is capped by
     * the package's own bound: a demo deeper than the explorer allows would
     * build folders the move dialog then refuses to accept.
     *
     * @return list<Folder>
     */
    protected function createFolders(Folder $root): array
    {
        $wanted = max(0, (int) $this->option('folders'));
        $maxDepth = max(1, (int) config('filament-file-explorer.folders.max_depth', 12));
        $depth = max(1, min((int) $this->option('depth'), $maxDepth, count(self::FOLDER_NAMES)));

        $manager = app(FileExplorerManager::class);
        $created = [];
        $parents = [$root];

        for ($level = 0; $level < $depth && count($created) < $wanted; $level++) {
            $names = self::FOLDER_NAMES[$level];
            shuffle($names);

            $next = [];

            foreach ($parents as $parent) {
                // Fewer children the deeper it goes, and never all of them:
                // an empty folder is a state the explorer has to draw too.
                $take = max(1, (int) round(count($names) / ($level + 2)));

                foreach (array_slice($names, 0, $take) as $name) {
                    if (count($created) >= $wanted) {
                        break 2;
                    }

                    $folder = $manager->createChild($parent, $name, $this->freeSlug($parent, $name));
                    $created[] = $folder;
                    $next[] = $folder;
                }
            }

            $parents = $next === [] ? $parents : $next;
        }

        return [$root, ...$created];
    }

    /**
     * A slug free under this parent, suffixed the way the component's own "new
     * folder" suffixes it.
     *
     * The table has unique(parent_id, slug), so a second run without --fresh
     * would collide on every name it repeats. A random suffix would dodge that
     * too, but it would also make the library un-reproducible from its seed —
     * and it would not be the slug the explorer itself would have written.
     */
    protected function freeSlug(Folder $parent, string $name): string
    {
        $base = Str::slug($name) ?: 'folder';
        $slug = $base;
        $index = 2;

        while (FolderModel::query()->where('parent_id', $parent->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }

    /**
     * @param  list<Folder>  $folders
     * @return array{list<Media>, int, int}
     */
    protected function createFiles(array $folders, ?Authenticatable $actor): array
    {
        $wanted = max(0, (int) $this->option('files'));
        $extensions = $this->flattenExtensions();

        if ($wanted === 0 || $extensions === []) {
            return [[], 0, 0];
        }

        $directory = $this->workingDirectory();
        $quota = app(Quota::class);
        $rootId = (int) $folders[0]->id;

        $stored = [];
        $bytes = 0;
        $refused = 0;

        $bar = $this->output->createProgressBar($wanted);
        $bar->start();

        // finally, because the files are built under storage/: a failure part
        // way through would otherwise leave them there, and the next run would
        // pile more on top of them.
        try {
            for ($index = 0; $index < $wanted; $index++) {
                [$kind, $extension] = $extensions[array_rand($extensions)];
                $folder = $folders[array_rand($folders)];
                $title = $this->titleFor($kind);

                $path = $this->writeDemoFile($directory, $extension, $title, mt_rand(1, 4));
                $size = (int) (filesize($path) ?: 0);

                // The same gate an upload passes, and for the same reason: a
                // demo that filled the scope past its cap would leave the first
                // thing anyone tries — uploading a file — refused.
                if (! $quota->reserve($rootId, $size)) {
                    @unlink($path);
                    $refused++;
                    $bar->advance();

                    continue;
                }

                $media = $this->store($folder, $path, $title, $extension, $actor);

                if ($media instanceof Media) {
                    $stored[] = $media;
                    $bytes += $size;
                }

                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine(2);

            $this->cleanUp($directory);
        }

        return [$stored, $bytes, $refused];
    }

    /**
     * Stored the way an upload stores it, clash handling included.
     */
    protected function store(Folder $folder, string $path, string $title, string $extension, ?Authenticatable $actor): ?Media
    {
        $label = $title.'.'.$extension;
        $fileName = Str::slug($title).'.'.$extension;

        $index = FileNames::availableIndex($folder, $fileName);
        $fileName = FileNames::applyIndex($fileName, $index);
        $label = FileNames::applyIndexToLabel($label, $index);

        try {
            return $folder
                ->addMedia($path)
                ->usingName($label)
                ->usingFileName($fileName)
                ->withCustomProperties([
                    'user_id' => $actor?->getAuthIdentifier(),
                    'uploaded_by_type' => $actor ? $actor::class : null,
                    'uploaded_by_id' => $actor?->getAuthIdentifier(),
                ])
                ->toMediaCollection(UploadRules::collection());
        } catch (CouldNotLoadImage $exception) {
            // Thrown while converting, after the row and the original are
            // written — so the file is there, only its thumbnail is not. The
            // upload path treats it the same way.
            report($exception);

            return Media::query()
                ->where('model_type', $folder->getMorphClass())
                ->where('model_id', $folder->id)
                ->where('collection_name', UploadRules::collection())
                ->where('file_name', $fileName)
                ->latest('id')
                ->first();
        }
    }

    /**
     * Tags and descriptions over part of the library, never all of it: a filter
     * that matches everything and a panel where every item is described show
     * neither feature off.
     *
     * @param  list<Folder>  $folders
     * @param  list<Media>  $files
     */
    protected function annotate(string $scopeKey, array $folders, array $files): int
    {
        if (! Annotations::enabled()) {
            return 0;
        }

        $annotations = app(Annotations::class);
        $names = array_keys(self::TAGS);
        $touched = 0;

        // Folders too. A folder can be tagged, and the listing narrows both —
        // filtering to a tag and losing the folders that carry it would hide
        // exactly what was tagged.
        $items = [
            ...array_map(fn (Folder $folder): array => [Annotations::FOLDER, (int) $folder->id, (string) $folder->name], array_slice($folders, 1)),
            ...array_map(fn (Media $media): array => [Annotations::FILE, (int) $media->id, (string) $media->name], $files),
        ];

        foreach ($items as [$type, $id, $name]) {
            $tags = mt_rand(1, 100) <= 40 ? mt_rand(1, 2) : 0;

            for ($index = 0; $index < $tags; $index++) {
                $tag = $names[array_rand($names)];
                $annotations->attach($scopeKey, $type, $id, $tag, self::TAGS[$tag]);
            }

            if (mt_rand(1, 100) <= 25) {
                $annotations->setDescription($type, $id, $name.' — '.implode(' ', $this->demoSentences(mt_rand(1, 3))));
            }

            $touched += $tags > 0 ? 1 : 0;
        }

        return $touched;
    }

    /**
     * A little in the trash, because an empty trash view shows nothing about it.
     *
     * Through Trash itself rather than by deleting: a trashed folder is soft
     * deleted while a trashed file moves collection, and hand-rolling either
     * half is how a demo ends up showing a state the explorer cannot produce.
     *
     * @param  list<Folder>  $folders
     * @param  list<Media>  $files
     */
    protected function fillTrash(array $folders, array $files): int
    {
        if (! Trash::enabled()) {
            return 0;
        }

        $trash = app(Trash::class);
        $trashed = 0;

        // Deepest first: trashing a folder takes its subtree with it, and the
        // trash view lists one entry per trashed thing — so a leaf keeps the
        // demo's remaining folders intact.
        $candidates = array_slice(array_reverse(array_slice($folders, 1)), 0, 1);

        foreach ($candidates as $folder) {
            $trash->trashFolder($folder);
            $trashed++;
        }

        foreach (array_slice($files, 0, min(3, count($files))) as $media) {
            // A file whose folder has just gone is already in the trash with it.
            if (in_array((int) $media->model_id, array_map(fn (Folder $f): int => (int) $f->id, $candidates), true)) {
                continue;
            }

            $trash->trashMedia($media->refresh());
            $trashed++;
        }

        return $trashed;
    }

    /**
     * @param  list<Folder>  $folders
     * @param  list<Media>  $files
     */
    protected function report(
        int $seed,
        array $folders,
        array $files,
        int $bytes,
        int $refused,
        int $tagged,
        int $trashed,
        string $scopeKey,
        Folder $root,
    ): void {
        $this->components->twoColumnDetail('Scope', $scopeKey);
        $this->components->twoColumnDetail('Root folder', $root->name.' (#'.$root->id.')');
        $this->components->twoColumnDetail('Folders created', (string) (count($folders) - 1));
        $this->components->twoColumnDetail('Files created', (string) count($files));
        $this->components->twoColumnDetail('Bytes written', Quota::format($bytes));
        $this->components->twoColumnDetail('Items tagged', (string) $tagged);
        $this->components->twoColumnDetail('Items trashed', (string) $trashed);
        $this->components->twoColumnDetail('Seed', (string) $seed);

        if ($refused > 0) {
            $this->newLine();
            $this->components->warn($refused.' file(s) skipped: the scope reached its quota.');
        }

        $this->newLine();
        $this->line('Same library again: php artisan file-explorer:demo --seed='.$seed.' --fresh');
    }

    /**
     * @return list<array{string, string}>
     */
    protected function flattenExtensions(): array
    {
        $flat = [];

        foreach ($this->demoExtensions() as $kind => $extensions) {
            foreach ($extensions as $extension) {
                $flat[] = [$kind, $extension];
            }
        }

        return $flat;
    }

    protected function titleFor(string $kind): string
    {
        $titles = self::FILE_TITLES[$kind] ?? ['Document'];

        return $titles[array_rand($titles)].' '.mt_rand(1, 99);
    }

    /**
     * Where the files are built before Media Library moves them.
     *
     * Under a directory of its own, so a second run in another terminal cannot
     * delete the files this one has not stored yet.
     */
    protected function workingDirectory(): string
    {
        $directory = storage_path(self::WORKING_DIRECTORY.'/'.Str::lower(Str::random(8)));

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Media Library moves what it stores, so what is left here is what a quota
     * refused — plus the directories themselves, which are this command's to
     * take away. The parent goes only when it is empty: another run may still
     * be building files in a sibling.
     */
    protected function cleanUp(string $directory): void
    {
        File::deleteDirectory($directory);

        $parent = storage_path(self::WORKING_DIRECTORY);

        if (is_dir($parent) && File::directories($parent) === [] && File::files($parent) === []) {
            File::deleteDirectory($parent);
        }
    }
}
