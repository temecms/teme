<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tree;

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportSource;
use App\Models\User;
use Tests\Fixtures\Import\ContentImports;

/**
 * A WordPress site's pages as an import left them (I-3363): each at the top
 * level, at its flat address, its WordPress address kept as an old one,
 * and its map row holding WordPress's parent, order and path, as the
 * content phase writes them. The caller connects the source first
 * (importFake() and importSource()).
 */
final class ImportedPages
{
    /**
     * Imports these pages, by WordPress ID: each `[title, slug, parent's
     * WordPress ID or 0, menu_order]`, parents before children. Answers
     * each page's item, by WordPress ID.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: int}>  $pages
     *
     * @return array<int, Content>
     */
    public static function import(ImportSource $source, User $author, array $pages): array
    {
        $paths = [];
        $items = [];

        foreach ($pages as $wordpressId => [$title, $slug, $parent, $order]) {
            $paths[$wordpressId] = $parent === 0 || ! isset($paths[$parent]) ? $slug : $paths[$parent].'/'.$slug;
            $result = ContentImports::import(ContentImports::item($author, [
                'type' => 'page', 'title' => $title, 'slug' => $slug, 'wordpressPath' => $paths[$wordpressId],
                'guid' => "https://old.example/?page_id={$wordpressId}",
            ]));

            ImportMapEntry::query()->create([
                'source_id' => $source->id, 'object_type' => 'post', 'object_id' => (string) $wordpressId,
                'teme_type' => 'content', 'teme_id' => $result['contentId'],
                'status' => ImportStatus::Imported, 'outcome' => ImportOutcome::Created, 'imported_at' => now(),
                'facts' => ['path' => $paths[$wordpressId], 'parent' => $parent, 'menu_order' => $order, 'written' => $result['written']],
            ]);

            $items[$wordpressId] = Content::withTrashed()->findOrFail($result['contentId']);
        }

        return $items;
    }
}
