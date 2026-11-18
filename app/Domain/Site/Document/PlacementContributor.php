<?php

/**
 * Something that adds fragments to a placement on every page (I-228): site
 * code, and in time a plugin's. Registered under Placements::CONTRIBUTORS
 * from its part's own provider, and resolved through the container each time
 * a page is drawn.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

interface PlacementContributor
{
    /**
     * Whom it speaks for, which decides whose fragment wins a key two share.
     */
    public function precedence(): Precedence;

    /**
     * Its fragments for this placement on this page; none where it adds nothing.
     *
     * @return list<Fragment>
     */
    public function fragments(Placement $placement, DocumentPage $page): array;
}
