<?php

/**
 * Core's head tags beside robots (I-290, I-292, I-293): the page's
 * description, its canonical, and the newer and older pages of a listing,
 * each from the values PageHead resolved for the page, the page's social
 * card: one set of Open Graph tags, its language among them, and
 * `twitter:card` (I-293), and an `hreflang` link to each of the page's
 * language versions, `x-default` to the default language's (I-306).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Head;

use App\Domain\Design\SiteDesign;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\Alternates;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\MetaTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\ViewModels\Alternate;
use Teme\Contracts\Settings\SettingsReader;

final class HeadTags implements HeadContributor
{
    public function __construct(
        private readonly PageHead $heads,
        private readonly SettingsReader $settings,
        private readonly Alternates $alternates,
        private readonly PageLanguage $language,
        private readonly SiteLanguages $languages,
        private readonly SiteDesign $design,
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

        $brand = $this->design->brand();

        if ($brand !== '') {
            $entries[] = MetaTag::name('theme-color', $brand);
        }

        $alternates = $this->alternates->for($page);

        if ($values->social()) {
            $entries = [...$entries, ...$this->social($values, $alternates)];
        }

        if ($values->canonical !== null) {
            $entries[] = new LinkTag(LinkRelation::Canonical, $values->canonical);
            $entries = [...$entries, ...self::hreflang($this->alternates->listed($page))];
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
     * @param  list<Alternate>  $alternates
     *
     * @return list<MetaTag>
     */
    private function social(HeadValues $values, array $alternates): array
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
        $tags[] = MetaTag::property('og:locale', $this->languages->ogLocale($this->language->tag()));

        foreach ($alternates as $alternate) {
            if (! $alternate->current) {
                $tags[] = MetaTag::property('og:locale:alternate', $this->languages->ogLocale($alternate->tag));
            }
        }

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

    /**
     * An `hreflang` link for each version, the page itself included, and
     * `x-default` to the default language's when it is among them; none
     * for a page with no other version. Only on a page with a canonical:
     * a page search engines are told not to list names no versions.
     *
     * @param  list<Alternate>  $alternates
     *
     * @return list<LinkTag>
     */
    private static function hreflang(array $alternates): array
    {
        if (count($alternates) < 2) {
            return [];
        }

        $links = [];

        foreach ($alternates as $alternate) {
            $links[] = new LinkTag(LinkRelation::Alternate, $alternate->url, hreflang: $alternate->tag);
        }

        foreach ($alternates as $alternate) {
            if ($alternate->isDefault) {
                $links[] = new LinkTag(LinkRelation::Alternate, $alternate->url, hreflang: 'x-default');
            }
        }

        return $links;
    }
}
