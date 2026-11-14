<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

/**
 * One `<link>` in the page's head (I-228): a relation from the allow-list,
 * an absolute address made when the page is drawn, never stored, and, for
 * an alternate, its media type, title and language. Each value is checked
 * when it is made and escaped when it is drawn.
 */
final readonly class LinkTag
{
    public function __construct(
        public LinkRelation $rel,
        public string $href,
        public ?string $type = null,
        public ?string $title = null,
        public ?string $hreflang = null,
    ) {
        // Absolute, as search engines want a canonical, and never a script's
        // address; a quote, a space or an angle bracket could end the attribute.
        if (preg_match('~^https?://[^\s"\'<>`]+$~i', $href) !== 1) {
            throw new InvalidArgumentException("A link's address must be absolute, http or https: [{$href}].");
        }

        if ($type !== null && preg_match('~^[a-z]+/[a-z0-9.+-]+$~', $type) !== 1) {
            throw new InvalidArgumentException("A link's type [{$type}] isn't a media type.");
        }

        if ($hreflang !== null && preg_match('/^(?:x-default|[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*)$/', $hreflang) !== 1) {
            throw new InvalidArgumentException("A link's hreflang [{$hreflang}] isn't a language tag.");
        }
    }

    /**
     * What makes two entries the same link, so the first one wins: its
     * relation, and for an alternate its address too.
     */
    public function identity(): string
    {
        return $this->rel->single() ? $this->rel->value : $this->rel->value.' '.$this->href;
    }

    /**
     * The tag, its attributes always in this order, each value escaped as
     * Blade's {{ }} escapes text.
     */
    public function html(): string
    {
        $html = '<link rel="'.$this->rel->value.'"';

        foreach (['hreflang' => $this->hreflang, 'type' => $this->type, 'title' => $this->title, 'href' => $this->href] as $attribute => $value) {
            if ($value !== null) {
                $html .= ' '.$attribute.'="'.e($value).'"';
            }
        }

        return $html.'>';
    }
}
