<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Hooks\Payloads;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ItemSnapshot;

/**
 * An item's working revision as the content reader gives it to a plugin
 * (docs/plans/plugin-sockets-3.md): its body, fields by handle, terms by
 * taxonomy, slug, the accounts it credits and its other language versions.
 * It reads only, and never checks who asks: the reader does.
 */
final readonly class ItemSnapshots
{
    public function __construct(private FieldRegistry $fields) {}

    /**
     * Returns the item's working revision as plain values.
     */
    public function of(Content $content): ItemSnapshot
    {
        /** @var ContentRevision $revision */
        $revision = ContentRevision::query()->findOrFail($content->working_revision_id);

        return new ItemSnapshot(
            Payloads::item($content),
            (string) $revision->id,
            (string) $content->slug,
            (array) $revision->document,
            $this->byHandle((string) $content->type, (array) $revision->fields),
            self::terms(array_map(strval(...), (array) $revision->terms)),
            self::credits((string) $content->id),
            self::translations($content),
        );
    }

    /**
     * Stored field values keyed by their handles; a value whose field the
     * type no longer has is left out.
     *
     * @param  array<string, mixed>  $byKey
     *
     * @return array<string, mixed>
     */
    private function byHandle(string $type, array $byKey): array
    {
        $handles = [];

        foreach ($this->fields->forType($type) as $field) {
            if (array_key_exists($field->key, $byKey)) {
                $handles[$field->handle] = $byKey[$field->key];
            }
        }

        return $handles;
    }

    /**
     * The revision's terms as slugs by taxonomy, in the revision's order.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, list<string>>
     */
    private static function terms(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('terms')->whereIn('id', $ids)->get(['id', 'taxonomy', 'slug'])->keyBy('id');
        $terms = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row !== null) {
                $terms[(string) $row->taxonomy][] = (string) $row->slug;
            }
        }

        return $terms;
    }

    /**
     * The accounts the item credits, in order. A person with no account has
     * no account ID to give, so is left out (I-3366).
     *
     * @return list<string>
     */
    private static function credits(string $contentId): array
    {
        return DB::table('content_bylines as b')
            ->join('people as p', 'p.id', '=', 'b.person_id')
            ->where('b.content_id', $contentId)
            ->whereNotNull('p.user_id')
            ->orderBy('b.position')
            ->pluck('p.user_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The item's other language versions, not in the trash.
     *
     * @return list<ItemRef>
     */
    private static function translations(Content $content): array
    {
        if ($content->translation_group === null) {
            return [];
        }

        return Content::query()
            ->where('translation_group', $content->translation_group)
            ->whereKeyNot($content->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Content $version): ItemRef => Payloads::item($version))
            ->values()
            ->all();
    }
}
