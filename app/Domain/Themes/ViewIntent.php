<?php

/**
 * What a public page is, for choosing its template: its kind, and what the
 * kind needs (study B §3d, I-442). `latest` is the latest posts; the site's
 * home page is either that or a `single`, and `isFront` says which page is
 * the home page without changing its candidates.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use InvalidArgumentException;

final readonly class ViewIntent
{
    /** @var list<string> */
    public const array KINDS = ['latest', 'single', 'archive', 'taxonomy', 'author', 'search', 'date', 'notfound', 'error', 'comments', 'respond', 'protected'];

    /**
     * @param  string  $kind  one of KINDS
     * @param  string|null  $type  the content type, for `single` and `archive`
     * @param  string|null  $template  the item's chosen page template, for `single`; null for none
     * @param  string|null  $taxonomy  the taxonomy's key, for `taxonomy`
     * @param  string|null  $term  the term's slug, for `taxonomy`
     * @param  int|null  $code  the HTTP status, for `error`
     * @param  bool  $isFront  the site's home page, or one of its numbered pages
     */
    public function __construct(
        public string $kind,
        public ?string $type = null,
        public ?string $template = null,
        public ?string $taxonomy = null,
        public ?string $term = null,
        public ?int $code = null,
        public bool $isFront = false,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown page kind [{$kind}].");
        }
    }
}
