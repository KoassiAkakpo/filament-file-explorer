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
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'zip', 'rar',
        ],
        'allowed_mime_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'image/png',
            'image/jpeg',
            'image/webp',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
        ],
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
    | Folders
    |--------------------------------------------------------------------------
    */
    'folders' => [
        'max_depth' => 12,
        'table' => 'file_explorer_folders',
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
