<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Concerns;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use Teme\Contracts\Cache\CacheKeysFor;

/**
 * What a change to a translation group purges (I-3360, D12): the pages that
 * drew each group's alternates, and every published member's own page,
 * whose alternates change. Read before and after the change.
 */
trait PurgesTranslations
{
    /**
     * @param  iterable<Content>  $members
     * @param  list<string|null>  $groups
     *
     * @return list<CacheKeysFor>
     */
    private static function translationKeys(iterable $members, array $groups): array
    {
        $tags = array_values(array_unique(array_map(PageTags::translations(...), array_values(array_filter($groups, fn (?string $group): bool => $group !== null)))));
        $keys = [];
        $first = '';

        foreach ($members as $member) {
            $first = $first === '' ? (string) $member->id : $first;

            if (! $member->trashed() && $member->status === 'published') {
                $keys[] = new CacheKeysFor(CacheKeysFor::CONTENT, (string) $member->id, [...$tags, PageTags::content((string) $member->id)]);
            }
        }

        if ($keys === [] && $tags !== []) {
            $keys[] = new CacheKeysFor(CacheKeysFor::CONTENT, $first, $tags);
        }

        return $keys;
    }

    /**
     * The tag of the pages that drew the item's group's alternates (D12), or
     * none for an item in no group.
     *
     * @return list<string>
     */
    private static function groupTags(Content $content): array
    {
        return $content->translation_group === null ? [] : [PageTags::translations((string) $content->translation_group)];
    }
}
