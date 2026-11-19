<?php

/**
 * The content types and taxonomies phase 1 has: post, page and region,
 * category and tag. The only place in app/ where those names decide
 * anything; everything else asks the registries. Pages have no taxonomies
 * (I-417).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Comments\Discussion;
use App\Domain\StructuredData\TypeSchema;

final class CoreTypes
{
    /** The type of a theme's region's content, which the region code asks for by this name. */
    public const string REGION = 'region';

    public static function register(ContentTypeRegistry $types, TaxonomyRegistry $taxonomies): void
    {
        $types->register(new ContentType('post', 'Post', 'Posts', 'posts', archive: true, taxonomies: ['category', 'tag'], supports: ['seo', 'excerpt', 'featured_image', 'template', 'sticky'], icon: 'file-text', pattern: '/{slug}/', comments: Discussion::Open, schema: new TypeSchema(mainEntity: 'BlogPosting', mainEntities: TypeSchema::ARTICLES), wordpress: 'post'));
        // Pages answer to their own six, editors’ and administrators’ alone, as in WordPress (I-3313).
        $types->register(new ContentType('page', 'Page', 'Pages', 'pages', hierarchical: true, supports: ['seo', 'excerpt', 'featured_image', 'template'], abilities: TypePermissions::names('page'), icon: 'files', pattern: '/{parent}/{slug}/', comments: Discussion::Closed, schema: new TypeSchema(mainEntities: TypeSchema::ARTICLES), wordpress: 'page'));
        // A theme's region (study B §3e): content with no address, no search and
        // no list of its own; Content › Regions and CreateRegion make each one.
        $types->register(new ContentType(self::REGION, 'Region', 'Regions', 'regions', public: false, searchable: false, listed: false, abilities: array_fill_keys(array_keys(ContentType::ACTIONS), 'regions.manage')));

        $taxonomies->register(new Taxonomy('category', 'Category', 'Categories', 'categories', hierarchical: true, icon: 'folder', base: 'category', wordpress: 'category'));
        $taxonomies->register(new Taxonomy('tag', 'Tag', 'Tags', 'tags', icon: 'tag', base: 'tag', wordpress: 'post_tag'));
    }
}
