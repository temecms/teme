<?php

/**
 * The media originals an export copied, their bytes, and the IDs of those it
 * couldn't read or whose file has changed since upload, for the manifest.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

final class MediaTally
{
    public int $files = 0;

    public int $bytes = 0;

    /** @var list<string> */
    public array $missing = [];

    /** @var list<string> */
    public array $differs = [];

    public function copied(CopiedOriginal $copy): void
    {
        $this->files++;
        $this->bytes += $copy->bytes;
    }

    public function missing(string $id): void
    {
        $this->missing[] = $id;
    }

    public function differs(string $id): void
    {
        $this->differs[] = $id;
    }
}
