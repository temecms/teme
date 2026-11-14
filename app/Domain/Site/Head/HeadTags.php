<?php

declare(strict_types=1);

namespace App\Domain\Site\Head;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Core's head tags beside robots (I-290, I-292, I-293): the page's
 * description, its canonical, and the newer and older pages of a listing,
 * each from the values PageHead resolved for the page, and the page's social
 * card: one set of Open Graph tags and `twitter:card` (I-293).
 */
final class HeadTags implements HeadContributor
{
    public function __construct(
        private readonly PageHead $heads,
        private readonly SettingsReader $settings,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        $values = $this->heads->for($page);
        $entries = [];

        if ($values->description !== null) {
            $entries[] = MetaTag::name('description', $values->description);
        }

        if ($values->social()) {
            $entries = [...$entries, ...$this->social($values)];
        }

        if ($values->canonical !== null) {
            $entries[] = new LinkTag(LinkRelation::Canonical, $values->canonical);
        }

        if ($values->prev !== null) {
            $entries[] = new LinkTag(LinkRelation::Prev, $values->prev);
        }

        if ($values->next !== null) {
            $entries[] = new LinkTag(LinkRelation::Next, $values->next);
        }

        return $entries;
    }

    /**
     * Open Graph's tags and `twitter:card`: X reads the `og:` tags, so no
     * `twitter:` title or image (docs/studies/seo.md).
     *
     * @return list<MetaTag>
     */
    private function social(HeadValues $values): array
    {
        $tags = [
            MetaTag::property('og:type', $values->socialType),
            MetaTag::property('og:title', (string) $values->socialTitle),
        ];

        if ($values->socialDescription !== null) {
            $tags[] = MetaTag::property('og:description', $values->socialDescription);
        }

        $url = $values->canonical ?? $values->url;

        if ($url !== null) {
            $tags[] = MetaTag::property('og:url', $url);
        }

        $tags[] = MetaTag::property('og:site_name', $this->settings->string('general', 'site_title'));

        if ($values->image !== null) {
            $tags[] = MetaTag::property('og:image', $values->image->url);
            $tags[] = MetaTag::property('og:image:width', (string) $values->image->width);
            $tags[] = MetaTag::property('og:image:height', (string) $values->image->height);

            if ($values->image->alt !== null) {
                $tags[] = MetaTag::property('og:image:alt', $values->image->alt);
            }
        }

        $tags[] = MetaTag::name('twitter:card', 'summary_large_image');

        return $tags;
    }
}
