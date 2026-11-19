<?php

/**
 * Search and social's section "Page and article types" (I-709): for each
 * public content type, what search engines are told its items are, set to
 * what the type declares until the site chooses otherwise. Made from the
 * registry, so a type registered later gets its own; an item's own choice
 * still comes first.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\Setting;

final class PageAndArticleTypes implements SearchAndSocialSection
{
    /** The page types' labels, as an item's own choice words them. */
    private const array PAGE_LABELS = ['WebPage' => 'Web page', 'AboutPage' => 'About page', 'ContactPage' => 'Contact page', 'CollectionPage' => 'Collection page'];

    /** The article types' labels; any other main entity goes by its schema.org name. */
    private const array ENTITY_LABELS = ['Article' => 'Article', 'NewsArticle' => 'News article', 'BlogPosting' => 'Blog post'];

    public function order(): int
    {
        return 350;
    }

    public function key(): string
    {
        return 'page-and-article-types';
    }

    public function label(): string
    {
        return 'Page and article types';
    }

    public function settings(): array
    {
        $since = '0.1.0';
        $settings = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if (! $type->public) {
                continue;
            }

            $schema = $type->schema ?? new TypeSchema;
            $plural = mb_strtolower($type->plural);
            $pages = [];

            foreach (TypeSchema::PAGE_TYPES as $pageType) {
                $pages[$pageType] = self::PAGE_LABELS[$pageType];
            }

            $settings[] = Setting::select(self::pageKey($type->key), "Page type for {$plural}", 'What search engines are told each of them is, unless one says otherwise in the editor.', $since, $pages)
                ->default($schema->pageType);

            if ($schema->mainEntities === []) {
                continue;
            }

            $entities = [];

            foreach ($schema->mainEntities as $entity) {
                $entities[$entity] = self::ENTITY_LABELS[$entity] ?? $entity;
            }

            $entities[TypeSchemas::NONE] = array_diff($schema->mainEntities, TypeSchema::ARTICLES) === [] ? 'Not an article' : 'Nothing';

            $settings[] = Setting::select(self::articleKey($type->key), "Article type for {$plural}", 'What kind of article search engines are told each of them is, if any, unless one says otherwise in the editor.', $since, $entities)
                ->default($schema->mainEntity ?? TypeSchemas::NONE);
        }

        return $settings;
    }

    /** The setting that holds the site's page type for a content type's items. */
    public static function pageKey(string $type): string
    {
        return "schema_page_type_{$type}";
    }

    /** The setting that holds the site's main entity for a content type's items, `none` for none. */
    public static function articleKey(string $type): string
    {
        return "schema_article_type_{$type}";
    }
}
