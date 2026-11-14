<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Media;

/**
 * The media Teme imported from the old site, as the converter asks for it
 * (I-338). The caller implements it: the posts phase on the import map,
 * tests and pages read at once with KnownMedia.
 */
interface MediaTargets
{
    /**
     * The media ID of the WordPress attachment with this ID, or null when it
     * wasn't imported.
     */
    public function attachment(int $id): ?string;

    /**
     * The media ID whose file, or one of its sizes or edited copies, sits at
     * this path below the uploads folder, a key as ConversionSite::fileKey()
     * makes it, or null.
     */
    public function file(string $path): ?string;
}
