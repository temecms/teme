<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Types\TaxonomyRegistry;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The feeds a public page links in its head (I-299): the site's on every
 * page, in the page's language (I-3360), a term's or an author's on their own archive, and a type's on its
 * archive page. Core's own
 * entries, so a site or a plugin can still say otherwise. There is no link
 * to a comment feed, which does not exist.
 */
final class FeedLinks implements HeadContributor
{
    public function __construct(
        private SettingsReader $settings,
        private FeedUrls $urls,
        private TaxonomyRegistry $taxonomies,
        private PageLanguage $page,
        private SiteLanguages $languages,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        $site = $this->settings->string('general', 'site_title');
        $prefix = $this->languages->prefix($this->page->tag());
        $entries = [$this->link($prefix === '' ? '/feed/' : "/{$prefix}/feed/", "{$site} » Feed")];
        $subject = $page->model instanceof ListingView ? $page->model->subject : null;
        $path = self::archivePath(request()->getPathInfo());

        if ($subject === null || $path === '') {
            return $entries;
        }

        $kind = $page->intent?->kind;
        $taxonomy = (string) $page->intent?->taxonomy;

        if ($kind === 'taxonomy' && $subject->kind === 'term') {
            $noun = $this->taxonomies->has($taxonomy) ? $this->taxonomies->get($taxonomy)->singular : 'Term';
            $entries[] = $this->link("/{$path}/feed/", "{$site} » {$subject->title} {$noun} Feed");
        } elseif ($kind === 'author' && $subject->kind === 'author') {
            $entries[] = $this->link("/{$path}/feed/", "{$site} » Posts by {$subject->title} Feed");
        } elseif ($kind === 'archive' && $subject->kind === 'type') {
            $entries[] = $this->link("/{$path}/feed/", "{$site} » {$subject->title} Feed");
        }

        return $entries;
    }

    /**
     * The stored path of the archive a request is for: the request's path
     * in the form addresses are stored in, less a trailing `page/{n}`.
     */
    public static function archivePath(string $requestPath): string
    {
        return (string) preg_replace('#(?:^|/)page/\d+$#', '', PublicRequestPath::normalise($requestPath));
    }

    private function link(string $path, string $title): LinkTag
    {
        return new LinkTag(LinkRelation::Alternate, $this->urls->absolute(PublicRequestPath::encode($path)), Rss2::TYPE, $title);
    }
}
