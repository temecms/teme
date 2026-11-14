<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use InvalidArgumentException;

/**
 * Every comment status (I-224): core's five, which phase 5 lets a plugin add
 * to. Operations ask it which statuses a moderator may choose, which readers
 * see and which one deletes from; nothing hard-codes a list of them.
 */
final class CommentStatusRegistry
{
    /** A status's key: a plain word, as stored in `comments.status` (varchar 32). */
    private const string KEY = '/^[a-z][a-z0-9-]{0,31}$/';

    /** @var array<string, CommentStatus> */
    private array $statuses = [];

    /**
     * Adds a status. Later (phase 5): a plugin registers its own through a
     * contract.
     */
    public function register(CommentStatus $status): void
    {
        if (preg_match(self::KEY, $status->key) !== 1) {
            throw new InvalidArgumentException("Comment status [{$status->key}] must be lower-case letters, digits and hyphens, starting with a letter, at most 32 characters.");
        }

        if (isset($this->statuses[$status->key])) {
            throw new InvalidArgumentException("Comment status [{$status->key}] is already registered.");
        }

        $this->statuses[$status->key] = $status;
    }

    /**
     * The status registered under $key.
     */
    public function get(string $key): CommentStatus
    {
        return $this->statuses[$key] ?? throw new InvalidArgumentException("No comment status [{$key}] is registered.");
    }

    public function has(string $key): bool
    {
        return isset($this->statuses[$key]);
    }

    /**
     * Every registered status, in registration order.
     *
     * @return list<CommentStatus>
     */
    public function all(): array
    {
        return array_values($this->statuses);
    }

    /**
     * The keys of the statuses a moderator may move a comment to.
     *
     * @return list<string>
     */
    public function settable(): array
    {
        return $this->keysWhere(fn (CommentStatus $status): bool => $status->settable);
    }

    /**
     * The keys of the statuses readers see a comment in.
     *
     * @return list<string>
     */
    public function shown(): array
    {
        return $this->keysWhere(fn (CommentStatus $status): bool => $status->shown);
    }

    /**
     * The keys of the statuses Delete permanently is offered from.
     *
     * @return list<string>
     */
    public function deletable(): array
    {
        return $this->keysWhere(fn (CommentStatus $status): bool => $status->deletable);
    }

    /**
     * @param  callable(CommentStatus): bool  $holds
     *
     * @return list<string>
     */
    private function keysWhere(callable $holds): array
    {
        return array_keys(array_filter($this->statuses, $holds));
    }
}
