<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

/**
 * Something that adds typed entries to every page's head (I-228): core's
 * robots, canonical and social tags, a site's verification tags, a plugin's
 * pieces of the graph. Registered under DocumentMeta::CONTRIBUTORS from its
 * part's own provider, and resolved through the container each time a page
 * is drawn, so it holds no state of its own.
 */
interface HeadContributor
{
    /**
     * Whom it speaks for, which decides whose entry wins a key two share.
     */
    public function precedence(): Precedence;

    /**
     * Its entries for this page, in the order it wants them; none for a
     * page it has nothing to say about.
     *
     * @return list<MetaTag|LinkTag|JsonLdNode>
     */
    public function entries(DocumentPage $page): array;
}
