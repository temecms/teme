<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;

/**
 * The site's verification tags (docs/studies/seo.md §3.12) on the home page,
 * where the services look for them, one escaped meta tag each, speaking for
 * the site. No other page reads them.
 */
final class VerificationTags implements HeadContributor
{
    public function precedence(): Precedence
    {
        return Precedence::Site;
    }

    public function entries(DocumentPage $page): array
    {
        if ($page->intent?->isFront !== true) {
            return [];
        }

        return array_map(fn (array $tag): MetaTag => MetaTag::name($tag['name'], $tag['value']), SiteVerification::tags());
    }
}
