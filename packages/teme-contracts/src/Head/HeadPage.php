<?php

declare(strict_types=1);

namespace Teme\Contracts\Head;

use Teme\Contracts\StructuredData\FaqCard;
use Teme\Contracts\StructuredData\StepsCard;

/**
 * A public page, as a plugin's head tags and structured data read it: its
 * kind, its addresses, its language, and for an article what it is, the
 * plugin's own Search and social values for it, and its FAQ and Steps
 * cards. Never anything about the visitor: the page is cached whole for
 * everyone.
 *
 * @since 1.1.0
 */
final readonly class HeadPage
{
    /**
     * @param  'article'|'home'|'latest'|'archive'|'term'|'author'|'date'|'search'|'other'  $kind
     * @param  string  $path  the page's path, relative, starting with / and with no query
     * @param  string  $address  the page's own absolute address, made now from the site's: what its nodes' @ids start with
     * @param  string  $home  the site's home address, absolute, made now
     * @param  string|null  $type  the content type of an article or a type's archive
     * @param  string|null  $contentId  an article's ID
     * @param  string|null  $title  an article's title
     * @param  array<string, mixed>  $own  an article's values from the plugin's own Search and social section, by key
     * @param  list<FaqCard>  $faqs  an article's FAQ cards, in the order they stand
     * @param  list<StepsCard>  $steps  an article's Steps cards, in the order they stand
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $kind,
        public string $path,
        public string $address,
        public string $home,
        public string $language,
        public ?string $type = null,
        public ?string $contentId = null,
        public ?string $title = null,
        public array $own = [],
        public array $faqs = [],
        public array $steps = [],
        public int $pageNumber = 1,
    ) {}
}
