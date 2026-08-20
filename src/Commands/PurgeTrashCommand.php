<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Commands;

use Illuminate\Console\Command;
use Koassi\FilamentFileExplorer\Support\Trash;

class PurgeTrashCommand extends Command
{
    protected $signature = 'file-explorer:purge-trash
                            {--days=30 : Only purge what has been in the trash for at least this long}';

    protected $description = 'Permanently delete explorer items that have been in the trash for a while';

    public function handle(): int
    {
        if (! Trash::enabled()) {
            $this->components->warn('The trash is disabled, so there is nothing to purge.');

            return self::SUCCESS;
        }

        $days = max(0, (int) $this->option('days'));
        $purged = app(Trash::class)->purgeOlderThan($days);

        $this->components->info($purged === 0
            ? 'Nothing was old enough to purge.'
            : $purged.' item(s) purged from the trash.');

        return self::SUCCESS;
    }
}
