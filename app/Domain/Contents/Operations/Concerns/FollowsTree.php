<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Concerns;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Contents\Tree\SubtreeOutcome;

/**
 * For an Operation that may change what the addresses of the pages under
 * an item are made of (I-3363): the tree lock first, before any row; the
 * children's chain read under the row lock; then the pages under it moved,
 * and the page cache told after commit. A type that doesn't nest, and a
 * page with nothing under it, cost nothing more than the lock and one look.
 */
trait FollowsTree
{
    /** The item's type, read where the Operation first reads the item, so perform() locks its tree before any row. */
    private ?string $treeType = null;

    /** The chain the item's children had before the change; null when nothing under it can move. */
    private ?string $chainBefore = null;

    /**
     * Takes the item's tree before any row lock, for a type whose items nest.
     */
    private function takeTree(): bool
    {
        return app(SubtreeAddresses::class)->lock($this->treeType);
    }

    /**
     * Reads, under the row lock, the chain the item's children have now,
     * when there are any.
     */
    private function noteChain(Content $content, bool $nests): void
    {
        $subtree = app(SubtreeAddresses::class);
        $this->chainBefore = $nests && $subtree->hasChildren($content) ? $subtree->childChain($content) : null;
    }

    /**
     * Moves the pages under the item after its own change, and tells the
     * page cache of each after commit.
     */
    private function followTree(Content $content, ?string $actorId): SubtreeOutcome
    {
        if ($this->chainBefore === null) {
            return new SubtreeOutcome;
        }

        $outcome = app(SubtreeAddresses::class)->follow($content, $this->chainBefore, $actorId);

        foreach ($outcome->purges as $purge) {
            $this->purgeAfterCommit($purge);
        }

        return $outcome;
    }
}
