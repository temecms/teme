<?php

/**
 * Blocks left out (I-335): core's dynamic blocks, which
 * WordPress draws when the page loads and Teme's theme does instead, and
 * tables of contents, each named in the report; page breaks, reported as
 * pages joined; spacers and markers, without a word.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\ParsedBlock;

final readonly class Dropped implements BlockMapping
{
    /** Core's dynamic blocks: those WordPress draws with a PHP file in wp-includes/blocks. */
    public const array DYNAMIC = [
        'core/archives', 'core/avatar', 'core/breadcrumbs', 'core/calendar', 'core/categories',
        'core/comment-author-name', 'core/comment-content', 'core/comment-date', 'core/comment-edit-link',
        'core/comment-reply-link', 'core/comment-template', 'core/comments', 'core/comments-pagination',
        'core/comments-pagination-next', 'core/comments-pagination-numbers', 'core/comments-pagination-previous',
        'core/comments-title', 'core/footnotes', 'core/home-link', 'core/latest-comments', 'core/latest-posts',
        'core/legacy-widget', 'core/loginout', 'core/navigation', 'core/navigation-link', 'core/navigation-overlay-close',
        'core/navigation-submenu', 'core/page-list', 'core/page-list-item', 'core/pattern', 'core/post-author',
        'core/post-author-biography', 'core/post-author-name', 'core/post-comments-count', 'core/post-comments-form',
        'core/post-comments-link', 'core/post-content', 'core/post-date', 'core/post-excerpt', 'core/post-featured-image',
        'core/post-navigation-link', 'core/post-template', 'core/post-terms', 'core/post-time-to-read', 'core/post-title',
        'core/query', 'core/query-no-results', 'core/query-pagination', 'core/query-pagination-next',
        'core/query-pagination-numbers', 'core/query-pagination-previous', 'core/query-title', 'core/query-total',
        'core/read-more', 'core/rss', 'core/search', 'core/site-logo', 'core/site-tagline', 'core/site-title',
        'core/social-link', 'core/social-links', 'core/tag-cloud', 'core/template-part', 'core/term-count',
        'core/term-description', 'core/term-name', 'core/term-template', 'core/terms-query', 'core/widget-group',
    ];

    /** Tables of contents: Teme's theme draws its own. */
    public const array CONTENTS_LISTS = ['simpletoc/toc', 'yoast-seo/table-of-contents', 'rank-math/toc-block'];

    /** Layout and markers, left out without a word. */
    public const array SILENT = ['core/spacer', 'core/more', 'core/icon', 'core/tab-list'];

    public function blocks(): array
    {
        return [...self::DYNAMIC, ...self::CONTENTS_LISTS, ...self::SILENT, 'core/nextpage'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $name = (string) $block->name;

        if (in_array($name, self::DYNAMIC, true)) {
            $context->report->count('block.dynamic', $name);
        } elseif (in_array($name, self::CONTENTS_LISTS, true)) {
            $context->report->count('block.contents-list', $name);
        } elseif ($name === 'core/nextpage') {
            $context->report->count('block.page-break');
        }

        return [];
    }
}
