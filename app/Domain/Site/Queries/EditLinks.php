<?php

declare(strict_types=1);

namespace App\Domain\Site\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Comments\CommentModeration;
use App\Domain\Contents\Models\Content;
use App\Domain\Site\CommentViews;
use App\Domain\Site\PathResolver;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The "Edit" link core's script adds to a public page for someone signed
 * in (I-163): for the one item the page at a path shows, at its own
 * address or at the site's as the chosen home page, to someone who may edit
 * it, by the rule the editor itself applies (ContentPolicy::update()), and whether they look after its comments (1.8c″). The
 * page is found from its path, never from an ID written in it: a content
 * ID says when its draft was made.
 */
final readonly class EditLinks
{
    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * The link to the editor for the item the public page at this path
     * shows, with its words, such as "Edit post"; null when the page shows
     * no one item, or this person may not edit it.
     *
     * @return array{href: string, label: string}|null
     */
    public function at(string $userId, string $path): ?array
    {
        $id = self::itemAt(PublicRequestPath::normalise($path));
        $user = $id === null ? null : User::query()->find((int) $userId);
        $content = $user === null ? null : Content::query()
            ->whereKey($id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_revision_id')
            ->where('published_at', '<=', now())
            // Private items are read in the admin, never here (I-1700).
            ->where('visibility', '<>', 'private')
            ->first();

        if ($user === null || $content === null || ! $this->types->has($content->type) || ! Gate::forUser($user)->allows('update', $content)) {
            return null;
        }

        return [
            'href' => route('content.edit', ['content' => $content->id]),
            'label' => __('Edit :type', ['type' => mb_strtolower($this->types->get($content->type)->singular)]),
        ];
    }

    /**
     * Whether this person looks after the comments of the item the public
     * page at this path shows (CommentModeration::allows()): the item's own
     * page, or a later page of its comments, which counts as the item
     * (1.8c″'s D2). False when the page shows no one item, the item isn't
     * live, or the person has no say over its comments.
     */
    public function looksAfterCommentsAt(string $userId, string $path): bool
    {
        $path = PublicRequestPath::normalise($path);
        $id = self::itemAt(preg_match(PathResolver::COMMENTS_PAGE, $path, $match) === 1 ? $match[1] : $path);
        $user = $id === null ? null : User::query()->find((int) $userId);
        $content = $id === null || $user === null ? null : CommentViews::live($id);

        return $user !== null && $content !== null && CommentModeration::allows($user, $content);
    }

    /**
     * The item a public page at this stored path shows by itself, as
     * PathResolver answers it: the page chosen as the home page at the
     * site's address, or the item whose current address this is, unless it
     * is the posts page, which lists the latest posts instead. Null for
     * anything else.
     */
    private static function itemAt(string $path): ?string
    {
        $settings = AddressSettings::current();
        $choosesPages = $settings->frontShows === AddressSettings::FRONT_PAGE;

        if ($path === '') {
            return $choosesPages && $settings->frontPageId !== '' ? $settings->frontPageId : null;
        }

        $row = Paths::find($path);

        if ($row === null || ! $row->is_current || $row->target_type !== 'content' || ($choosesPages && $row->target_id === $settings->postsPageId)) {
            return null;
        }

        return (string) $row->target_id;
    }
}
