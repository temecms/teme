<?php

/**
 * The language of the public page being drawn (I-3360), set once per
 * request by whatever decided what the page is. Unset, as in the admin, a
 * queue job or the console, it is the site's default language.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use Illuminate\Support\Facades\App;
use InvalidArgumentException;

final class PageLanguage
{
    private ?string $tag = null;

    private ?string $stored = null;

    public function __construct(private readonly SiteLanguages $languages) {}

    /**
     * Draws the page in this language: remembers it and sets the
     * application's locale to it.
     *
     * @throws InvalidArgumentException for a tag that is not one of the site's languages
     */
    public function use(string $tag): void
    {
        if (! $this->languages->has($tag)) {
            throw new InvalidArgumentException("[{$tag}] is not one of the site's languages.");
        }

        App::setLocale($this->languages->locale($tag));
        $this->tag = $tag;
        $this->stored = $this->languages->stored($tag);
    }

    /**
     * The page's language: the tag given to use(), else the default.
     */
    public function tag(): string
    {
        return $this->tag ?? $this->languages->defaultTag();
    }

    /**
     * The page's language as a row stores it: null for the default, and
     * null while unset. Reads nothing, so what settings draw can ask it.
     */
    public function stored(): ?string
    {
        return $this->stored;
    }

    /**
     * Whether something has set the page's language this request.
     */
    public function isSet(): bool
    {
        return $this->tag !== null;
    }
}
