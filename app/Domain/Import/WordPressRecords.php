<?php

/**
 * WordPress's own records kept as posts, which are not a site's content:
 * never listed as custom types, never checked for an address and never
 * brought, by a live import or a file's alike.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

final class WordPressRecords
{
    /**
     * The post types WordPress keeps its own records in: the Customizer's
     * CSS and changesets, embeds it has fetched, privacy requests, a block
     * theme's styles, templates and fonts, and revisions. Menu items,
     * navigation and reusable blocks are not among them: the import brings
     * those its own way.
     *
     * @var list<string>
     */
    public const array TYPES = [
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_global_styles',
        'wp_template',
        'wp_template_part',
        'wp_font_family',
        'wp_font_face',
        'revision',
    ];

    /**
     * The status WordPress gives an item opened in the editor and never
     * saved: a placeholder, not content.
     */
    public const string AUTO_DRAFT = 'auto-draft';

    /**
     * Whether an item of this type, with this status, is one of WordPress's
     * own records rather than the site's content.
     */
    public static function internal(string $type, string $status = ''): bool
    {
        return in_array($type, self::TYPES, true) || $status === self::AUTO_DRAFT;
    }
}
