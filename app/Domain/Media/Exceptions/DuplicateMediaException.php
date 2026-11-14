<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use RuntimeException;

/**
 * The uploaded file is byte-for-byte an item the library already has. The
 * uploader chooses to use that one or to upload anyway.
 */
final class DuplicateMediaException extends RuntimeException
{
    public function __construct(public readonly string $existingId)
    {
        parent::__construct('This file is already in the media library.');
    }
}
