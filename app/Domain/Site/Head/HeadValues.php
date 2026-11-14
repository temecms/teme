<?php

declare(strict_types=1);

namespace App\Domain\Site\Head;

/**
 * What one public page's head resolves to (I-290 to I-293): its title,
 * description, addresses, robots directives and social card, for the head's
 * contributors and for the parts that describe the page elsewhere.
 */
final readonly class HeadValues
{
    /**
     * @param  list<string>  $robots  the robots directives in the order they are written; [] for none
     */
    public function __construct(
        /** The document's title, as plain text: `<title>` and nothing else. */
        public string $title,
        /** The meta description, as plain text, or null for none. */
        public ?string $description,
        /** The page's own absolute address, or null for a 404, an error and core's reply pages. */
        public ?string $url,
        /** The canonical, absolute, or null: a hidden page, a search, a 404, an error and core's reply pages have none. */
        public ?string $canonical,
        public array $robots,
        /** Open Graph's title, or null where no social tags are drawn. */
        public ?string $socialTitle,
        /** Open Graph's description, or null for none. */
        public ?string $socialDescription,
        /** `article` for an item that isn't the site's address, else `website`. */
        public string $socialType,
        /** The social image, or null for none. */
        public ?SocialImage $image,
        /** The newer page of a listing, absolute, or null. */
        public ?string $prev,
        /** The older page of a listing, absolute, or null. */
        public ?string $next,
    ) {}

    /** Whether search engines may list the page: no `noindex` among its directives. */
    public function listed(): bool
    {
        return ! in_array('noindex', $this->robots, true);
    }

    /** The directives as one robots meta's content, or null for none. */
    public function robotsContent(): ?string
    {
        return $this->robots === [] ? null : implode(', ', $this->robots);
    }

    /** Whether the page draws Open Graph and `twitter:card` tags. */
    public function social(): bool
    {
        return $this->socialTitle !== null;
    }
}
