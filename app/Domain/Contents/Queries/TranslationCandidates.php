<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Languages\SiteLanguages;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The items the editor's "Link a different post…" offers as an item's
 * version in one language (docs/proposals/multilingual-screens.md): live
 * items of its type in that language, outside its group, that the person
 * may change, found by title. It reads only; LinkTranslations links.
 */
final class TranslationCandidates
{
    /**
     * Up to $limit items, most recently changed first, each with its ID,
     * title and status; none for a language the site doesn't have; null
     * when the item is gone or the person may not change it.
     *
     * @return list<array{id: string, title: string, status: string}>|null
     */
    public static function search(string $contentId, string $language, string $query, string $viewerId, int $limit = 10): ?array
    {
        $content = Content::query()->find($contentId);
        $viewer = User::query()->find($viewerId);

        if ($content === null || $viewer === null || ! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        $languages = app(SiteLanguages::class);

        if (! $languages->has($language)) {
            return [];
        }

        $stored = $languages->stored($language);
        $needle = trim($query);
        $gate = Gate::forUser($viewer);

        return Content::query()
            ->where('type', $content->type)
            ->whereKeyNot($content->id)
            ->when($stored === null, fn ($builder) => $builder->whereNull('language'), fn ($builder) => $builder->where('language', $stored))
            ->when($content->translation_group !== null, fn ($builder) => $builder->where(fn ($inner) => $inner->whereNull('translation_group')->orWhere('translation_group', '<>', $content->translation_group)))
            ->when($needle !== '', fn ($builder) => $builder->where('title', 'ilike', '%'.addcslashes($needle, '%_\\').'%'))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (Content $item): bool => $gate->allows('update', $item))
            ->take($limit)
            ->map(fn (Content $item): array => ['id' => (string) $item->id, 'title' => (string) $item->title, 'status' => (string) $item->status])
            ->values()
            ->all();
    }
}
