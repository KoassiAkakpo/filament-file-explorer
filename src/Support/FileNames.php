<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves file name clashes inside a folder.
 *
 * Folders are protected by a unique index on (parent_id, slug); files were not
 * protected by anything, so two uploads of the same name simply coexisted and
 * the explorer showed the same label twice with no way to tell them apart.
 */
final class FileNames
{
    /**
     * Lowest suffix that leaves $fileName free in $folder — 1 when the name is
     * already free.
     */
    public static function availableIndex(Folder $folder, string $fileName): int
    {
        $taken = Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', (new Folder)->getMorphClass())
            ->where('model_id', $folder->id)
            ->pluck('file_name')
            ->map(fn ($name): string => strtolower((string) $name))
            ->all();

        if (! in_array(strtolower($fileName), $taken, true)) {
            return 1;
        }

        $index = 2;

        while (in_array(strtolower(self::applyIndex($fileName, $index)), $taken, true)) {
            $index++;
        }

        return $index;
    }

    /**
     * "report.pdf" with index 2 becomes "report-2.pdf", matching the slug style
     * of the stored file names.
     */
    public static function applyIndex(string $fileName, int $index): string
    {
        if ($index <= 1) {
            return $fileName;
        }

        [$base, $extension] = self::split($fileName);

        return $base.'-'.$index.($extension === '' ? '' : '.'.$extension);
    }

    /**
     * "Report.pdf" with index 2 becomes "Report (2).pdf": the label is what the
     * user reads, so it follows the convention they know from a desktop.
     */
    public static function applyIndexToLabel(string $label, int $index): string
    {
        if ($index <= 1) {
            return $label;
        }

        [$base, $extension] = self::split($label);

        return $base.' ('.$index.')'.($extension === '' ? '' : '.'.$extension);
    }

    public static function existing(Folder $folder, string $fileName): ?Media
    {
        return Media::query()
            ->where('collection_name', UploadRules::collection())
            ->where('model_type', (new Folder)->getMorphClass())
            ->where('model_id', $folder->id)
            ->whereRaw('LOWER(file_name) = ?', [strtolower($fileName)])
            ->first();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $fileName): array
    {
        $extension = (string) pathinfo($fileName, PATHINFO_EXTENSION);
        $base = (string) pathinfo($fileName, PATHINFO_FILENAME);

        return [$base === '' ? $fileName : $base, $extension];
    }
}
