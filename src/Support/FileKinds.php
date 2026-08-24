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

    /**
     * Narrows to *any* of several kinds — the restriction a picker imposes,
     * beside the single kind the filter menu applies.
     *
     * Here rather than in the picker, and expressed in SQL rather than as a PHP
     * matcher, for the reason the whole class exists: one list and one predicate,
     * so what the menu offers, what a listing shows and what a pick accepts can
     * never answer differently. The callers that check an id in PHP run this
     * against the same query instead of testing a mime type themselves.
     *
     * @param  Builder<Media>  $query
     * @param  list<string>  $kinds
     * @return Builder<Media>
     */
    public static function applyAny(Builder $query, array $kinds): Builder
    {
        $kinds = self::normaliseMany($kinds);

        // Empty narrows nothing, as a null kind does: a restriction nobody set
        // must not empty a folder.
        if ($kinds === []) {
            return $query;
        }

        if (count($kinds) === 1) {
            return self::apply($query, $kinds[0]);
        }

        return $query->where(function (Builder $group) use ($kinds): void {
            foreach ($kinds as $kind) {
                if (isset(self::PREFIXES[$kind])) {
                    $group->orWhere('mime_type', 'like', self::PREFIXES[$kind].'%');

                    continue;
                }

                $group->orWhereIn('mime_type', self::MIMES[$kind] ?? []);
            }
        });
    }

    /**
     * The known kinds among these, deduplicated and in the menu's own order so
     * a restriction reads the same wherever it is drawn.
     *
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function normaliseMany(array $kinds): array
    {
        $known = array_filter(array_map(
            fn ($kind): ?string => self::normalise(is_string($kind) ? $kind : null),
            $kinds,
        ));

        return array_values(array_filter(self::all(), fn (string $kind): bool => in_array($kind, $known, true)));
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
