<?php

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

/**
 * One step of a Steps card: its title, its words as plain text and as the
 * page draws them, its image's address, and the address of the step on the
 * page, all made from the site's address now.
 *
 * @since 1.1.0
 */
final readonly class Step
{
    /**
     * @param  int  $position  1 for the first step
     * @param  string  $title  plain text
     * @param  string  $text  the step's words as plain text
     * @param  string  $html  the step as the page draws it: paragraphs, lists, links and emphasis only
     * @param  string|null  $image  the image's absolute address, or null
     * @param  string  $url  the page's address and the step's fragment, absolute
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $id,
        public int $position,
        public string $title,
        public string $text,
        public string $html,
        public ?string $image,
        public string $url,
    ) {}
}
