<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaLabel
{
    public static function display(Media $media): string
    {
        $ext = strtolower((string) pathinfo((string) $media->file_name, PATHINFO_EXTENSION));
        $base = trim((string) ($media->name ?: pathinfo((string) $media->file_name, PATHINFO_FILENAME)));

        if ($base === '') {
            return (string) $media->file_name;
        }

        if ($ext === '') {
            return $base;
        }

        if (str_ends_with(strtolower($base), '.'.$ext)) {
            return $base;
        }

        return $base.'.'.$ext;
    }

    /**
     * The short kind label the inspector shows under the name: DOCX rather
     * than application/vnd.openxmlformats-officedocument.wordprocessingml.document.
     *
     * The mime type is what the listing sorts and filters on, because that is
     * the only portable thing to do it in SQL — but it is not what a person
     * recognises a file by, and the long office types push every other row of
     * the panel out of the column. The mime type stays the fallback for a file
     * with no extension to show, since something is better than nothing there.
     */
    public static function kind(Media $media): string
    {
        $ext = strtoupper((string) pathinfo((string) $media->file_name, PATHINFO_EXTENSION));

        if ($ext !== '') {
            return $ext;
        }

        $mime = trim((string) $media->mime_type);

        return $mime !== '' ? $mime : '—';
    }
}
