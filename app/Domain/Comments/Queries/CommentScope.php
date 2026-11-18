<?php

/**
 * Whose comments a person looks after (I-483, I-570), as SQL over `contents`
 * (1.8c's D2): every item for comments.moderate holders; for anyone else,
 * the items of the types that take comments whose publish ability they
 * hold, every one with the type's edit.any and their own without it. It
 * restates CommentModeration::allows(), through ContentPolicy::publish() as
 * ContentAbilities reads it; CommentScopeTest holds the two together.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentModeration;
use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Database\Query\Builder;

final readonly class CommentScope
{
    /**
     * @param  list<string>  $everyItemOf  the types whose every item the person looks after
     * @param  list<string>  $ownItemsOf  the types whose own items alone they look after
     */
    private function __construct(
        public bool $everyItem,
        public array $everyItemOf,
        public array $ownItemsOf,
        public int $viewerId,
    ) {}

    /**
     * One person's scope, read once: their abilities for each type that
     * takes comments, the only types SubmitComment writes comments on.
     */
    public static function forViewer(User $viewer): self
    {
        if ($viewer->can(CommentModeration::EVERY_ITEM)) {
            return new self(true, [], [], (int) $viewer->id);
        }

        $every = [];
        $own = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->comments === null) {
                continue;
            }

            $abilities = ContentAbilities::forUser($viewer, $type->key);

            if ($abilities->publish && $abilities->editAny) {
                $every[] = $type->key;
            } elseif ($abilities->publish) {
                $own[] = $type->key;
            }
        }

        return new self(false, $every, $own, (int) $viewer->id);
    }

    /**
     * Whether the person looks after any item's comments at all: what opens
     * the Comments screen and its menu entry (D18).
     */
    public function any(): bool
    {
        return $this->everyItem || $this->everyItemOf !== [] || $this->ownItemsOf !== [];
    }

    /**
     * Narrows a query that reads `contents` (or the alias given) to the
     * items whose comments the person looks after: no condition for
     * comments.moderate holders, and no row for someone who looks after none.
     */
    public function constrain(Builder $query, string $contents = 'contents'): void
    {
        if ($this->everyItem) {
            return;
        }

        $query->where(function (Builder $scope) use ($contents): void {
            // False on its own, so a person with no types matches no row.
            $scope->whereRaw('false');

            if ($this->everyItemOf !== []) {
                $scope->orWhereIn("{$contents}.type", $this->everyItemOf);
            }

            if ($this->ownItemsOf !== []) {
                $scope->orWhere(fn (Builder $own): Builder => $own
                    ->whereIn("{$contents}.type", $this->ownItemsOf)
                    ->where("{$contents}.author_id", $this->viewerId));
            }
        });
    }

    /**
     * Whether the person looks after the comments of an item already read,
     * by its type and author: constrain()'s rule, for a list of rows.
     */
    public function covers(string $type, ?int $authorId): bool
    {
        return $this->everyItem
            || in_array($type, $this->everyItemOf, true)
            || (in_array($type, $this->ownItemsOf, true) && $authorId === $this->viewerId);
    }
}
