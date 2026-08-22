<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The kinds a listing can be narrowed to.
 *
 * Matched on `mime_type` and never on the extension, for the same reason the
 * sort by type is: extracting an extension is not portable across drivers, and
 * the filter has to run in SQL or the totals and "load more" would count rows
 * the window then dropped. The mime type is also the half worth trusting — it is
 * sniffed from the bytes, while a name is whatever the uploader typed.
 *
 * Only files are narrowed. A folder has no kind, exactly as it has no size, and
 * hiding folders would leave the user filtered into a room with no doors: the
 * one thing you still need while looking for images is a way to go and look
 * somewhere else.
 */
final class FileKinds
{
    /**
     * Prefix matches, for families where the type is the prefix.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'image' => 'image/',
        'audio' => 'audio/',
        'video' => 'video/',
    ];

    /**
     * Exact matches, for families that share no prefix.
     *
     * @var array<string, list<string>>
     */
    private const MIMES = [
        'pdf' => ['application/pdf'],
        'document' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/rtf',
            'text/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
        ],
        'spreadsheet' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/vnd.oasis.opendocument.spreadsheet',
        ],
        'presentation' => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'archive' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
        ],
    ];

    /**
     * Menu order: the families people look for first, not alphabetical.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return ['image', 'pdf', 'document', 'spreadsheet', 'presentation', 'archive', 'audio', 'video'];
    }

    public static function isKnown(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::all(), true);
    }

    /**
     * Null and anything unrecognised narrow nothing, so a stale value in a
     * request never empties a folder without explanation.
     */
    public static function normalise(?string $kind): ?string
    {
        return self::isKnown($kind) ? $kind : null;
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public static function apply(Builder $query, ?string $kind): Builder
    {
        $kind = self::normalise($kind);

        if ($kind === null) {
            return $query;
        }

        if (isset(self::PREFIXES[$kind])) {
            return $query->where('mime_type', 'like', self::PREFIXES[$kind].'%');
        }

        return $query->whereIn('mime_type', self::MIMES[$kind] ?? []);
    }

    public static function icon(string $kind): string
    {
        return match ($kind) {
            'image' => 'heroicon-o-photo',
            'pdf' => 'heroicon-o-document',
            'document' => 'heroicon-o-document-text',
            'spreadsheet' => 'heroicon-o-table-cells',
            'presentation' => 'heroicon-o-presentation-chart-bar',
            'archive' => 'heroicon-o-archive-box',
            'audio' => 'heroicon-o-musical-note',
            'video' => 'heroicon-o-film',
            default => 'heroicon-o-document',
        };
    }
}
