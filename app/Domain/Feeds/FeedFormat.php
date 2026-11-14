<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

/**
 * A way of writing a Feed (I-308): its name, the type it is served as, and
 * the document. RSS 2.0 is the one that exists; Atom and JSON Feed are one
 * more class each.
 */
interface FeedFormat
{
    /**
     * Its short name, such as `rss2`.
     */
    public function name(): string;

    /**
     * The Content-Type it is served as, with its charset.
     */
    public function contentType(): string;

    /**
     * The feed as one document.
     */
    public function render(Feed $feed): string;
}
