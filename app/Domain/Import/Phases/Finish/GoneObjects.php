<?php

/**
 * What became of each object a catch-up finds WordPress deleted since
 * (I-345): whether its Teme object is still here as a person would want it
 * listed, and the words that say so.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Comments\CommentStatus;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class GoneObjects
{
    /** Characters of a title or name in the words. */
    private const int TITLE = 120;

    public function __construct(private ContentTypeRegistry $types, private TaxonomyRegistry $taxonomies) {}

    /**
     * Of these map rows, those whose Teme object is still here, each with
     * the words that say WordPress deleted it. A person matched by email
     * predates the import and is never listed (I-1706).
     *
     * @param  list<object>  $rows  map rows with object_id, teme_type, teme_id and facts
     *
     * @return list<array{wordpressId: string, temeType: string, temeId: string, words: string}>
     */
    public function stillHere(array $rows): array
    {
        $here = [];

        foreach ($rows as $row) {
            $facts = is_array($row->facts) ? $row->facts : (array) json_decode((string) $row->facts, true);
            $type = (string) $row->teme_type;
            $id = (string) $row->teme_id;
            $words = match ($type) {
                'content' => $this->item($id),
                'media' => $this->file($id),
                'term' => $this->term($id),
                'user' => ($facts['matched'] ?? false) === true ? null : $this->person($id),
                'comment' => $this->comment($id),
                'navigation' => $this->menu($id),
                default => null,
            };

            if ($words !== null) {
                $here[] = ['wordpressId' => (string) $row->object_id, 'temeType' => $type, 'temeId' => $id, 'words' => $words];
            }
        }

        return $here;
    }

    private function item(string $id): ?string
    {
        $row = DB::table('contents')->where('id', $id)->whereNull('deleted_at')->first(['type', 'title']);

        if ($row === null) {
            return null;
        }

        $noun = $this->types->has((string) $row->type) ? mb_strtolower($this->types->get((string) $row->type)->singular) : 'item';

        return "The {$noun} “".self::cut((string) $row->title).'” was deleted or moved to the trash on WordPress since it was imported. It’s still here: move it to the trash if it should go.';
    }

    private function file(string $id): ?string
    {
        $title = DB::table('media')->where('id', $id)->whereNull('deleted_at')->value('title');

        return $title === null ? null : 'The file “'.self::cut((string) $title).'” was deleted on WordPress since it was imported. It’s still in Media: delete it there if it should go.';
    }

    private function term(string $id): ?string
    {
        $row = DB::table('terms')->where('id', $id)->first(['taxonomy', 'name']);

        if ($row === null) {
            return null;
        }

        $noun = $this->taxonomies->has((string) $row->taxonomy) ? mb_strtolower($this->taxonomies->get((string) $row->taxonomy)->singular) : 'term';

        return "The {$noun} “".self::cut((string) $row->name).'” was deleted on WordPress since it was imported. It’s still here: delete it if it should go.';
    }

    private function person(string $id): ?string
    {
        $name = DB::table('users')->where('id', $id)->value('name');

        return $name === null ? null : self::cut((string) $name).'’s account was deleted on WordPress since it was imported. They can still sign in here: remove them in People if they shouldn’t.';
    }

    private function comment(string $id): ?string
    {
        $title = DB::table('comments as m')->join('contents as c', 'c.id', '=', 'm.content_id')
            ->where('m.id', $id)->whereIn('m.status', [CommentStatus::APPROVED, CommentStatus::PENDING])->value('c.title');

        return $title === null ? null : 'A comment on “'.self::cut((string) $title).'” was deleted on WordPress since it was imported. It’s still here: move it to the trash in Comments if it should go.';
    }

    private function menu(string $id): ?string
    {
        $name = DB::table('navigations')->where('id', $id)->value('name');

        return $name === null ? null : 'The menu “'.self::cut((string) $name).'” was deleted on WordPress since it was imported. It’s still in Navigation: delete it there if it should go.';
    }

    private static function cut(string $text): string
    {
        return Str::limit($text, self::TITLE);
    }
}
