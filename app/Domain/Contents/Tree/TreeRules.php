<?php

/**
 * The rules for an item's parent (I-3363), in one place: each Operation asks
 * in validate() for the friendly answer and again under the tree lock in
 * perform(), so two moves at once can't make a loop.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use App\Domain\Contents\Models\Content;
use App\Domain\Feeds\FeedPath;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentTypeRegistry;

final class TreeRules
{
    public function __construct(private readonly ContentTree $tree, private readonly ContentTypeRegistry $types) {}

    /**
     * The words that refuse putting the item under $parentId, or null when
     * it may go there; null for the top level.
     */
    public function refusal(Content $item, ?string $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        $type = $this->types->get((string) $item->type);

        if (! $type->hierarchical) {
            return __(':plural cannot have a parent.', ['plural' => $type->plural]);
        }

        $parent = Content::withTrashed()->find($parentId, ['id', 'type', 'title', 'language', 'translation_group', 'deleted_at']);

        if ($parent === null) {
            return __('That page no longer exists. Choose another parent.');
        }

        if ($parent->trashed()) {
            return __('That page is in the trash. Restore it, or choose another parent.');
        }

        if ($parent->type !== $item->type) {
            return __('The parent must be another :singular.', ['singular' => mb_strtolower($type->singular)]);
        }

        if ($parent->language !== $item->language) {
            return $this->otherLanguage($item, $parent);
        }

        if (in_array((string) $item->id, $this->tree->ancestorIds($parentId), true)) {
            return __("A page can't sit under itself or under a page beneath it.");
        }

        if ($this->tree->levels($parentId) + $this->tree->height((string) $item->id) > ContentTree::MOST_LEVELS) {
            return __('Pages go eight levels deep at most.');
        }

        // An address ending in a feed's name answers the parent's feed (app/Domain/Feeds/FeedPath.php).
        if (in_array((string) $item->slug, FeedPath::NAMES, true)) {
            return __("A page under another page can't be called :slug: that address is kept for feeds. Choose another.", ['slug' => $item->slug]);
        }

        return null;
    }

    /**
     * The words for a parent in another language (I-3360): its version in
     * the item's language named when its group has one.
     */
    private function otherLanguage(Content $item, Content $parent): string
    {
        $languages = app(SiteLanguages::class);
        $language = $languages->label($languages->of($item->language));
        $version = $parent->translation_group === null ? null : Content::query()
            ->where('translation_group', $parent->translation_group)
            ->when($item->language === null, fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $item->language))
            ->value('title');

        return $version === null
            ? __("A page's parent is in its own language, and “:parent” has no :language version yet.", ['parent' => (string) $parent->title, 'language' => $language])
            : __("A page's parent is in its own language: choose the :language “:title”.", ['language' => $language, 'title' => (string) $version]);
    }
}
