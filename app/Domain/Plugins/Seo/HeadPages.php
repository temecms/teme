<?php

/**
 * What a plugin is told about a public page: its kind, addresses, language
 * and, for an article, its own values and cards (docs/guides/developers/plugins/seo.md).
 * Built from core's head values, so the title and the head's entries are
 * asked about the same page.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Cards\ArticleCards;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Head\HeadValues;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\StructuredData\GraphAddresses;
use Closure;
use Teme\Contracts\Head\HeadPage;

final readonly class HeadPages
{
    /** The kinds of page plugins are asked about, as ViewIntent names them. */
    public const array KINDS = ['single' => 'article', 'latest' => 'latest', 'archive' => 'archive', 'taxonomy' => 'term', 'author' => 'author', 'date' => 'date', 'search' => 'search'];

    public function __construct(private SiteLanguage $language, private ArticleCards $cards) {}

    /**
     * What every plugin is told about the page, given its own values and core's head values for it; null
     * for a page with no address of its own.
     *
     * @return (Closure(SeoPlugin): HeadPage)|null
     */
    public function for(DocumentPage $page, HeadValues $values): ?Closure
    {
        $address = $values->url;

        if ($address === null || $address === '') {
            return null;
        }

        $intent = $page->intent;
        $model = $page->model;
        $kind = $intent !== null && $intent->isFront && in_array($intent->kind, ['single', 'latest'], true) ? 'home' : self::KINDS[$intent !== null ? $intent->kind : 'single'];
        $home = GraphAddresses::home();
        $path = '/'.ltrim((string) parse_url($address, PHP_URL_PATH), '/');
        $language = $this->language->tag();
        $pageNumber = (int) request()->query('page', '1');

        if (! $model instanceof ArticleView) {
            return fn (SeoPlugin $plugin): HeadPage => new HeadPage($kind, $path, $address, $home, $language, $intent?->type, pageNumber: max(1, $pageNumber));
        }

        [$faqs, $steps] = $this->cards->for($model->revisionId, $address);

        return fn (SeoPlugin $plugin): HeadPage => new HeadPage(
            kind: $kind,
            path: $path,
            address: $address,
            home: $home,
            language: $language,
            type: $model->type,
            contentId: $model->id,
            title: $model->title,
            own: PluginSearchFields::valuesOf($plugin->code, $model->fields),
            faqs: $faqs,
            steps: $steps,
        );
    }
}
