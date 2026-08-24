<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'filament-file-explorer:install
                            {--force : Overwrite existing published files}
                            {--stubs : Also publish stubs (only needed to customize generators)}
                            {--migrate : Run migrations after install}';

    protected $description = 'Install Filament File Explorer (config; stubs optional)';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'filament-file-explorer-config',
            '--force' => $this->option('force'),
        ]);

        if ($this->option('stubs')) {
            $this->call('vendor:publish', [
                '--tag' => 'filament-file-explorer-stubs',
                '--force' => $this->option('force'),
            ]);
        }

        if ($this->option('migrate')) {
            $this->call('migrate', ['--no-interaction' => true]);
        }

        $this->call('filament:assets');

        $this->call('vendor:publish', [
            '--tag' => 'filament-file-explorer-assets',
            '--force' => $this->option('force'),
        ]);

        $this->components->info('Filament File Explorer installed.');
        $this->newLine();
        $this->line('Fast path:');
        $this->line('  1. php artisan vendor:publish --provider="Spatie\\MediaLibrary\\MediaLibraryServiceProvider" --tag="medialibrary-migrations"');
        $this->line('  2. php artisan migrate');
        $this->line('  3. Register plugin: ->plugin(\\Koassi\\FilamentFileExplorer\\FilamentFileExplorerPlugin::make())');
        $this->line('  4. php artisan filament:assets');
        $this->line('  5. php artisan vendor:publish --tag=filament-file-explorer-assets');
        $this->line('  6. Add to Filament theme.css (required for Tailwind classes in views):');
        $this->line('       @source \'../../../../vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php\';');
        $this->line('     then: npm run build  (and ->viteTheme(...))');
        $this->newLine();
        $this->line('That is all the standalone page needs — after step 3 a "'.__('filament-file-explorer::file-explorer.explorer').'"');
        $this->line('entry appears in the panel navigation and the root folder is created on first visit.');
        $this->newLine();
        $this->line('Optional — fill it with something to look at:');
        $this->line('  php artisan file-explorer:demo');
        $this->newLine();
        $this->line('Optional — explorer scoped to a record instead of the panel:');
        $this->line('  a. Model: use HasFileExplorer; + folder_id column');
        $this->line('     php artisan filament-file-explorer:make-folder-migration {table}');
        $this->line('  b. Pages: php artisan filament-file-explorer:make-page {Resource}');
        $this->newLine();
        $this->line('Optional — customise the standalone pages:');
        $this->line('  php artisan filament-file-explorer:make-page --standalone');
        $this->newLine();
        $this->comment('Stubs stay in package. Publish only to customize generators:');
        $this->comment('  php artisan filament-file-explorer:install --stubs');
        $this->comment('  # or: php artisan vendor:publish --tag=filament-file-explorer-stubs');

        $this->hintPanelPlugin();

        return self::SUCCESS;
    }

    protected function hintPanelPlugin(): void
    {
        $provider = app_path('Providers/Filament/AdminPanelProvider.php');

        if (! is_file($provider)) {
            return;
        }

        $contents = File::get($provider);

        if (str_contains($contents, 'FilamentFileExplorerPlugin')) {
            $this->components->info('AdminPanelProvider already registers FilamentFileExplorerPlugin.');

            return;
        }

        $this->components->warn('Add FilamentFileExplorerPlugin::make() to AdminPanelProvider.');
    }
}
