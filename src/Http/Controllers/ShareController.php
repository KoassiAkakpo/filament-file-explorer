<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Koassi\FilamentFileExplorer\Http\Controllers\Concerns\ServesMediaFiles;
use Koassi\FilamentFileExplorer\Support\Sharing;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves a file to whoever holds a live share link.
 *
 * The only route in the package with no authenticated user behind it, so what it
 * does *not* do matters as much as what it does:
 *
 * - It takes no scope key, no folder id and no media id. The token is the only
 *   input, and everything else is read off the row — a link cannot be edited
 *   into a link for another file.
 * - It never reads an ability. There is nobody to ask; the ability was checked
 *   when the link was made.
 * - It still runs containment, through Sharing::mediaFor(), so a file moved out
 *   of scope, trashed or deleted stops being served with no bookkeeping.
 * - It answers 404 for a bad token, an expired one, a revoked one and a file
 *   that has gone, all alike. Telling them apart would say whether a token was
 *   ever real.
 *
 * No conversions either: a share is for the file that was shared, not for a
 * rendition named in the query string.
 */
class ShareController extends Controller
{
    use ServesMediaFiles;

    public function show(Request $request, string $token): Response
    {
        abort_unless(Sharing::enabled(), 404);

        $sharing = app(Sharing::class);
        $share = $sharing->resolve($token);

        abort_if($share === null, 404);

        $media = $sharing->mediaFor($share);

        abort_if($media === null, 404);

        $sharing->recordView($share);

        return $this->fileResponse(
            $media,
            $request->boolean('download')
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
        );
    }
}
