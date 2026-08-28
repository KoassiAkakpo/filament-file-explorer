<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Thumbnails;
use Koassi\FilamentFileExplorer\Support\UploadLimits;
use Koassi\FilamentFileExplorer\Support\UploadRules;

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
        $this->reportUploadCeiling();
        $this->warnMissingThumbnailTooling();

        return self::SUCCESS;
    }

    /**
     * The size an upload may actually be, and which setting decides it.
     *
     * `upload.max_size_kb` reads like the ceiling and is not one: four other
     * limits sit under it and the lowest wins. On a stock host the honest answer
     * is Media Library's 10 MB — so a host who read 50 MB in *our* config file
     * discovers the real number from a user whose upload was refused, which is
     * the worst place to learn it.
     *
     * Printed here because the install command is the one moment the host is
     * looking at this package's own output, and it is safe to re-run.
     */
    protected function reportUploadCeiling(): void
    {
        $max = UploadLimits::maxUploadBytes();
        $promised = UploadRules::maxSizeKb() * 1024;
        $binding = UploadLimits::bindingConstraint();

        $this->newLine();
        $this->components->info('Largest file this host will accept: '.($max === null ? 'no limit' : Quota::format($max)));

        foreach (UploadLimits::constraints() as $name => $bytes) {
            $this->line(sprintf(
                '  %s %-46s %s',
                $name === $binding ? '→' : ' ',
                $name,
                $bytes === null ? 'no limit' : Quota::format($bytes),
            ));
        }

        if (UploadLimits::chunkingEngages()) {
            $this->line('  Sliced uploads are engaged, so the three request-shaped limits above do not cap a file.');
        }

        // The one thing worth interrupting for: our own setting promises more
        // than the installation can deliver.
        if ($max !== null && $max < $promised && $binding !== null && $binding !== 'filament-file-explorer.upload.max_size_kb') {
            $this->newLine();
            $this->components->warn(sprintf(
                'upload.max_size_kb is set to %s, but %s caps it at %s. Raise that setting too, or lower ours so the two agree.',
                Quota::format($promised),
                $binding,
                Quota::format($max),
            ));
        }
    }

    /**
     * Says which thumbnail kinds were asked for and will not appear.
     *
     * "Why are there no thumbnails on my PDFs" has four possible answers — the
     * feature is off, the kind is not listed, the tooling is missing, or the
     * conversion threw — and from the panel they look identical. The third is the
     * one nothing else can tell you, so it is said here.
     */
    protected function warnMissingThumbnailTooling(): void
    {
        $missing = Thumbnails::unavailable();

        if ($missing === [] || ! Thumbnails::enabled()) {
            return;
        }

        $this->newLine();
        $this->components->warn('Thumbnail kinds asked for in config that this host cannot produce:');

        foreach ($missing as $kind => $reason) {
            $this->line('  '.$kind.' — '.$reason);
        }
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
