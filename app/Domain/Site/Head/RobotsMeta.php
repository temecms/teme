<?php

/**
 * Core's robots meta (I-291, I-714): `noindex, nofollow` on every page while
 * the site discourages search engines; otherwise `noindex, nofollow` on
 * core's pages about writing a comment, `noindex, follow` on a search's page
 * and on a hidden page, nothing on a 404 or an error, and on every other page
 * the default directives, shaped by the item's own snippet controls.
 * directives() builds the list; PageHead resolves it once for the page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Head;

use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\WholeSite;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;

final class RobotsMeta implements HeadContributor
{
    /** Pages whose status already keeps them out of search engines' lists. */
    private const array UNLISTED = ['notfound', 'error'];

    public function __construct(private readonly PageHead $heads) {}

    /**
     * The page's robots directives, in the order they are written (I-291,
     * I-714): the switch first, whatever the page; core's pages about a
     * comment and a search; none for a 404 or an error; a hidden page's
     * noindex; or else the defaults, with the item's snippet controls.
     *
     * @param  string|null  $kind  the page's ViewIntent kind
     * @param  bool  $hidden  the item, its type or its archive's row hides the page
     * @param  array<string, mixed>  $fields  the item's stored fields, [] for any other page
     *
     * @return list<string>
     */
    public static function directives(?string $kind, bool $hidden, array $fields = []): array
    {
        if (WholeSite::discouraged()) {
            return ['noindex', 'nofollow'];
        }

        if ($kind === 'respond' || $kind === 'protected') {
            return ['noindex', 'nofollow'];
        }

        if ($kind === 'search') {
            return ['noindex', 'follow'];
        }

        if ($kind === null || in_array($kind, self::UNLISTED, true)) {
            return [];
        }

        // A page left out of the lists has no snippet to shape.
        if ($hidden) {
            return ['noindex', 'follow'];
        }

        $length = $fields[BuiltInFields::MAX_SNIPPET] ?? null;
        $directives = ($fields[BuiltInFields::NOSNIPPET] ?? false) === true
            ? ['nosnippet']
            : ['max-snippet:'.(is_numeric($length) && $length >= 0 ? (int) $length : -1)];

        $directives[] = 'max-image-preview:large';
        $directives[] = 'max-video-preview:-1';

        if (($fields[BuiltInFields::NOARCHIVE] ?? false) === true) {
            $directives[] = 'noarchive';
        }

        return $directives;
    }

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        $directives = $this->heads->for($page)->robotsContent();

        return $directives === null ? [] : [MetaTag::name('robots', $directives)];
    }
}
