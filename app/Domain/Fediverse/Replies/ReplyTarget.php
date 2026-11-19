<?php

/**
 * What a reply answers (docs/plans/fediverse-publishing.md, D4): one of the
 * site's federated items, by its object address, or a reply already taken
 * as a comment on one, by that reply's address.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Publishing\ObjectDocuments;
use App\Domain\Fediverse\Publishing\PublishingSettings;
use App\Domain\Settings\Modules;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;

final readonly class ReplyTarget
{
    private function __construct(
        public Content $item,
        public ?Comment $parent,
    ) {}

    /**
     * What this address names, or null when it is none of the site's posts or replies.
     */
    public static function of(string $inReplyTo): ?self
    {
        $object = FediverseObject::query()->whereKey($inReplyTo)->whereNull('deleted_at')->first();

        if ($object === null) {
            return null;
        }

        if ($object->kind === FediverseObject::POST) {
            $item = $object->content_id === null ? null : Content::query()->find($object->content_id);

            return $item === null ? null : new self($item, null);
        }

        $parent = $object->comment_id === null ? null : Comment::query()->find($object->comment_id);
        $item = $parent === null ? null : Content::query()->find($parent->content_id);

        return $parent === null || $item === null ? null : new self($item, $parent);
    }

    /**
     * Why the item takes no comment now, or null when it does: comments on,
     * the item live, public and federated, of a type that takes comments,
     * open, within the days the settings allow; and the comment answered,
     * if any, shown.
     */
    public function closedBecause(): ?string
    {
        $item = $this->item;
        $types = app(ContentTypeRegistry::class);

        if (! Modules::on(Modules::COMMENTS)) {
            return 'comments are switched off';
        }

        if (! ObjectDocuments::isLive($item) || ! app(PublishingSettings::class)->federates($item->type)
            || ! $types->has($item->type) || $types->get($item->type)->comments === null) {
            return 'the item takes no comments';
        }

        $days = DiscussionSettings::current()->closeAfterDays;
        $published = CarbonImmutable::parse((string) $item->published_at);

        if ($item->comment_status !== Discussion::Open || ($days > 0 && $published->lt(now()->subDays($days)))) {
            return 'comments on the item are closed';
        }

        if ($this->parent !== null && $this->parent->status !== CommentStatus::APPROVED) {
            return 'the comment it answers is not shown';
        }

        return null;
    }
}
