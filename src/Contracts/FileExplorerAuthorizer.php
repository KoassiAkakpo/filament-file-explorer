<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Contracts;

use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

interface FileExplorerAuthorizer
{
    public function canAccess(string $scopeKey, int $rootFolderId): bool;

    /**
     * Answer all of these. One left out is read as a denial.
     *
     * The explorer may come to ask about abilities that are not on this list —
     * it never reads them from here directly. Support\Abilities resolves them,
     * and one this implementation does not answer inherits the answer of the
     * ability it is a variation of (`share` follows `download`), so an
     * implementation written today keeps working and keeps meaning what its
     * author meant. Answer a new key explicitly to override that.
     *
     * @return array{
     *     browse: bool,
     *     search: bool,
     *     getInfo: bool,
     *     download: bool,
     *     upload: bool,
     *     mkdir: bool,
     *     rename: bool,
     *     move: bool,
     *     copy: bool,
     *     delete: bool,
     *     deleteFolder: bool
     * }
     */
    public function abilities(string $scopeKey, int $rootFolderId): array;

    /**
     * @return array{
     *     allowed: bool,
     *     reason_code: string|null,
     *     reason: string|null,
     *     remaining_seconds: int|null,
     *     window_seconds: int
     * }
     */
    public function mediaDeleteState(string $scopeKey, Media $media): array;

    /**
     * @return array{
     *     allowed: bool,
     *     reason_code: string|null,
     *     reason: string|null,
     *     remaining_seconds: int|null,
     *     window_seconds: int
     * }
     */
    public function folderDeleteState(string $scopeKey, Folder $folder): array;
}
