<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\Head\PageHead;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingView;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\StructuredData\NodeIds;

/**
 * Core's structured data on every public page (I-701): the site and who
 * publishes it, and the page with what it is about, from the head's resolved values and the
 * page's view model. Never a theme's: themes carry no functionality.
 */
final readonly class CoreGraph implements HeadContributor
{
    public function __construct(
        private PageHead $heads,
        private SettingsReader $settings,
        private SiteLanguage $language,
        private ItemNodes $items,
        private ListingNodes $listings,
        private PublisherNodes $publisher,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        $publisher = $this->publisher->for($page->model);
        $home = GraphAddresses::home();
        $site = new JsonLdNode([
            '@id' => $home.NodeIds::WEBSITE,
            '@type' => 'WebSite',
            'url' => $home,
            'name' => $this->settings->string('general', 'site_title'),
            'alternateName' => $publisher['siteAlternateName'],
            'description' => trim($this->settings->string('general', 'tagline')),
            'inLanguage' => $this->language->tag(),
            'publisher' => $publisher['reference'],
        ]);

        $model = $page->model;
        $kind = $page->intent?->kind;

        $about = match (true) {
            $model instanceof ArticleView => $this->items->for($model, $this->heads->for($page)),
            $model instanceof LatestView => $this->listings->for($model->feed, $kind, $this->heads->for($page), $model->breadcrumbs),
            $model instanceof ListingView => $this->listings->for($model, $kind, $this->heads->for($page), $model->breadcrumbs),
            default => [],
        };

        return [$site, ...$publisher['nodes'], ...$about];
    }
}
