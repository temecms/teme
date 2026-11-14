<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * Turns an address on this site back into what it leads to, so a link
 * written as an address is stored by ID (rule 9): an article, a term, an
 * author or a library file. LinkResolver answers the other way, at render
 * time.
 */
interface InternalLinks
{
    /**
     * What a link to this address leads to, by ID, or null. The address
     * comes without its query or place. A path on this site leads to the
     * article, the term or the author whose address, current or old, it
     * is; a library file's public address on the media disk leads to the
     * file: as a path where the disk is served from this site, in full where
     * it is served from another host (I-1702). The home page, a feed, a
     * later page of a list, a date archive, a path nothing has and any other
     * site's address are all null.
     *
     * @return array{kind: string, id: string}|null
     */
    public function targetAt(string $address): ?array;
}
