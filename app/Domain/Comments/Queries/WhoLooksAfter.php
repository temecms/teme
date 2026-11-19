<?php

/**
 * Who looks after one item's comments, as the page that refuses them to
 * someone else names it: the item's author, when they look after them, and
 * the plural of its type for "anyone who may edit other people's posts".
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class WhoLooksAfter
{
    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * Returns the name of the item's author when they look after its
     * comments, else null, and its type's plural in lower case.
     *
     * @return array{owner: string|null, plural: string}
     */
    public function of(string $contentId): array
    {
        $row = DB::table('contents')->where('id', $contentId)->first(['id', 'type', 'author_id']);
        $type = $row === null ? '' : (string) $row->type;
        $plural = $this->types->has($type) ? mb_strtolower($this->types->get($type)->plural) : 'items';
        $author = $row === null || $row->author_id === null ? null : User::query()->find((int) $row->author_id);

        if ($author === null) {
            return ['owner' => null, 'plural' => $plural];
        }

        $looksAfter = DB::table('contents')->where('contents.id', $contentId);
        CommentScope::forViewer($author)->constrain($looksAfter);

        return ['owner' => $looksAfter->exists() ? (string) $author->name : null, 'plural' => $plural];
    }
}
