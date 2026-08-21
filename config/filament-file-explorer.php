<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Bind your own implementation of FileExplorerAuthorizer in the host app,
    | or override this class name in config after publishing.
    |
    */
    'authorizer' => Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer::class,

    /*
    |--------------------------------------------------------------------------
    | Media collection
    |--------------------------------------------------------------------------
    */
    'collection' => 'file-explorer',

    /*
    |--------------------------------------------------------------------------
    | Auto-create root folder
    |--------------------------------------------------------------------------
    |
    | When a model uses HasFileExplorer, create a root folder on creating
    | if folder_id is empty.
    |
    */
    'auto_create_root' => true,

    /*
    |--------------------------------------------------------------------------
    | Legacy morph class
    |--------------------------------------------------------------------------
    |
    | Set when migrating from another folder/media implementation so existing
    | media.model_type values keep resolving correctly.
    |
    */
    'morph_class' => null,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'file-explorer/media',
        'middleware' => ['web', 'auth'],
        'name' => 'filament-file-explorer.media.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload rules
    |--------------------------------------------------------------------------
    */
    'upload' => [
        'max_size_kb' => 51200,

        /*
         | Defaults cover every kind the shipped mime icons can render, so a
         | file the explorer knows how to display is not rejected on upload.
         | SVG is deliberately absent: the media route serves files inline, and
         | an SVG runs script in the panel's own origin.
         */
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'rtf', 'odt', 'txt',
            'xls', 'xlsx', 'csv', 'ods',
            'ppt', 'pptx', 'odp',
            'zip', 'rar', '7z', 'gz', 'tar',
            'mp3', 'wav', 'ogg', 'm4a',
            'mp4', 'mov', 'webm',
            'png', 'jpg', 'jpeg', 'gif', 'webp',
        ],
        'allowed_mime_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/rtf',
            'text/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
            'audio/mpeg',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/mp4',
            'audio/x-m4a',
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
        ],

        /*
         | What happens when the target folder already holds a file with the
         | same name: 'rename' keeps both by suffixing " (2)", 'replace' drops
         | the existing one, 'skip' refuses the new one.
         */
        'on_conflict' => 'rename',
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    |
    | How many items the explorer renders at once. The rest is reachable with
    | "Load more", which raises the window by this amount again. Sorting and
    | windowing run in SQL, so this is what keeps a folder holding thousands of
    | files responsive.
    |
    */
    'listing' => [
        'per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    |
    | The explorer renders a "thumbnail" conversion instead of the original, so
    | a folder of photos costs kilobytes rather than megabytes. Needs GD or
    | Imagick — both come through spatie/image, which Media Library already
    | requires; there is nothing extra to install.
    |
    | Not queued by default: Media Library queues conversions unless told
    | otherwise, and a host with no worker running would never get a thumbnail.
    |
    | Files uploaded before this existed have no thumbnail, and the media route
    | quietly serves their original instead. To fill them in:
    |
    |   php artisan media-library:regenerate "Koassi\FilamentFileExplorer\Models\Folder" \
    |       --only=thumbnail --only-missing
    |
    | Config only, not a plugin setting: conversions are registered on the model,
    | which knows nothing about the panel it is browsed from.
    |
    */
    'thumbnails' => [
        'enabled' => true,
        'width' => 320,
        'height' => 320,
        'queued' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage quota
    |--------------------------------------------------------------------------
    |
    | Maximum number of bytes a scope may hold, or null for no limit. The cap
    | applies per root folder, so with the per-user or per-tenant resolver each
    | user or tenant gets an allowance of this size. Trashed files still count:
    | they occupy the disk until they are purged.
    |
    | Uploads and copies that would go over are refused, with the reason. The
    | sidebar shows the usage as soon as a limit is set.
    |
    |   'bytes' => 10 * 1024 ** 3,   // 10 GiB per scope
    |
    | Set it per panel instead with FilamentFileExplorerPlugin::make()->quota(...).
    |
    */
    'quota' => [
        'bytes' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic refresh
    |--------------------------------------------------------------------------
    |
    | Seconds between two automatic refreshes of the explorer, or null for none
    | (the default). Two people browsing the same root see nothing of each
    | other's uploads, renames and deletions until they navigate; a value here
    | closes that gap without any broadcasting to set up.
    |
    | The refresh is skipped while the tab is hidden, while an item is being
    | dragged, and while the user is renaming, creating a folder, uploading or
    | looking at a dialog — and it keeps the window a "Load more" has widened.
    |
    | Set it per panel instead with FilamentFileExplorerPlugin::make()->refreshEvery(...).
    |
    */
    'refresh' => [
        'seconds' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    |
    | With the trash on, deleting moves folders and files aside instead of
    | destroying them: folders are soft-deleted, files move to the collection
    | below, and both can be restored or purged from the explorer's trash view.
    | Turn it off to delete permanently, as before.
    |
    | `php artisan file-explorer:purge-trash --days=30` empties what has been
    | sitting there for a while; schedule it if you want automatic cleanup.
    |
    */
    'trash' => [
        'enabled' => true,
        'collection' => 'file-explorer-trash',
    ],

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    |
    | max_depth is also settable per panel, with
    | FilamentFileExplorerPlugin::make()->maxFolderDepth(...).
    |
    */
    'folders' => [
        'max_depth' => 12,
        'table' => 'file_explorer_folders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default view
    |--------------------------------------------------------------------------
    |
    | The view the explorer opens in: grid, list, table or details. Whatever the
    | user picks afterwards is remembered per scope, so this only applies until
    | they choose. Per panel: ->defaultViewMode('list').
    |
    */
    'view' => [
        'default' => 'grid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Standalone page
    |--------------------------------------------------------------------------
    |
    | The panel-level File Explorer page, registered by the plugin and reachable
    | from the navigation menu — no Eloquent record involved. Anything here can
    | also be set fluently on the plugin, which takes precedence and keeps the
    | settings panel-scoped:
    |
    |   FilamentFileExplorerPlugin::make()
    |       ->slug('library')
    |       ->rootFolder('Library', 'library')
    |       ->navigationGroup('Content')
    |
    */
    'standalone' => [

        // Master switch for the standalone page.
        'enabled' => true,

        // Let the plugin call $panel->pages([...]). Turn off to register the
        // page classes yourself in the panel provider.
        'register_pages' => true,

        // Also register the flat files table page.
        'files_page' => true,

        // Route slugs, relative to the panel path.
        'slug' => 'file-explorer',
        'files_slug' => 'file-explorer/files',

        // Authorization / session namespace passed to FileExplorerAuthorizer.
        'scope_key' => 'library',

        /*
         | Resolves the scope key and root folder id. Ships with:
         |   Resolvers\GlobalRootResolver    — one shared root (default)
         |   Resolvers\PerUserRootResolver   — one root per authenticated user
         |   Resolvers\PerTenantRootResolver — one root per Filament tenant
         | Or bind your own FileExplorerRootResolver implementation.
         */
        'resolver' => Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver::class,

        // Root folder created on first visit if missing.
        'root' => [
            'name' => 'Library',
            'slug' => 'library',
        ],

        'navigation' => [
            'enabled' => true,
            'files_enabled' => false,
            'label' => null,
            'icon' => 'heroicon-o-folder',
            'group' => null,
            'sort' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Livewire component
    |--------------------------------------------------------------------------
    */
    'livewire_component' => Koassi\FilamentFileExplorer\Livewire\FileExplorer::class,

];
