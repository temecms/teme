<?php

/**
 * One `<link>` in the page's head (I-228): a relation from the allow-list,
 * an absolute address made when the page is drawn, never stored, and, for
 * an alternate, its media type, title and language. Each value is checked
 * when it is made and escaped when it is drawn.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

final readonly class LinkTag
{
    public function __construct(
        public LinkRelation $rel,
        public string $href,
        public ?string $type = null,
        public ?string $title = null,
        public ?string $hreflang = null,
        /**
         * An icon's sizes, such as "32x32", or null when the link has none.
         *
         * @since 1.8.0
         */
        public ?string $sizes = null,
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

        if ($sizes !== null && preg_match('/^(?:\d+x\d+|any)(?:\s+(?:\d+x\d+|any))*$/', $sizes) !== 1) {
            throw new InvalidArgumentException("A link's sizes [{$sizes}] isn't a sizes list.");
        }
    }

    /**
     * What makes two entries the same link, so the first one wins: its
     * relation, and for an alternate its address too, and its language
     * when it has one, so `x-default` stands beside the version it names;
     * for an icon, its sizes so 32x32 stands beside the SVG.
     */
    public function identity(): string
    {
        if ($this->rel->single()) {
            return $this->rel->value;
        }

        if ($this->rel === LinkRelation::Icon) {
            return $this->rel->value.' '.($this->sizes ?? '').' '.($this->type ?? '').' '.$this->href;
        }

        return $this->rel->value.($this->hreflang === null ? '' : ' '.$this->hreflang).' '.$this->href;
    }

    /**
     * The tag, its attributes always in this order, each value escaped as
     * Blade's {{ }} escapes text.
     */
    public function html(): string
    {
        $html = '<link rel="'.$this->rel->value.'"';

        foreach (['hreflang' => $this->hreflang, 'sizes' => $this->sizes, 'type' => $this->type, 'title' => $this->title, 'href' => $this->href] as $attribute => $value) {
            if ($value !== null) {
                $html .= ' '.$attribute.'="'.e($value).'"';
            }
        }

        return $html.'>';
    }
}
