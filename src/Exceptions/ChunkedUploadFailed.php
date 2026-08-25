<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A sliced upload that cannot go on, with a status the browser can act on.
 *
 * Deliberately not a flat 403 or 404 like the media routes answer. There the
 * client is a visitor and telling apart "no such file" from "not yours" would
 * leak; here the client is this package's own JS, mid-upload, and the difference
 * between *start over*, *resend that slice* and *this file is too big* is the
 * difference between recovering and showing a shrug.
 */
class ChunkedUploadFailed extends HttpException
{
    /** The upload is not open any more: expired, cancelled, or never begun. */
    public static function gone(): self
    {
        return new self(410, 'This upload is no longer open. Start it again.');
    }

    /** A slice arrived out of order — the client is told which one is expected. */
    public static function outOfOrder(int $expected): self
    {
        return new self(409, 'Out of order: chunk '.$expected.' is the next one expected.');
    }

    public static function tooLarge(int $limit): self
    {
        return new self(413, 'This upload is larger than the '.$limit.' bytes this installation accepts.');
    }

    /** The partial could not be written — a disk that is full or not writable. */
    public static function unwritable(): self
    {
        return new self(500, 'The upload could not be written to temporary storage.');
    }
}
