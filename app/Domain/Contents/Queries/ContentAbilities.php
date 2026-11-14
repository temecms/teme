<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;

/**
 * What one person may do with one content type's items, read once per
 * request from the abilities the type names (1.5·0), so a list of fifty rows
 * asks no policy per row. The rules restate ContentPolicy's `update` and
 * `publish` exactly: if either changes there, it changes here too, and
 * OthersWorkTest compares the two.
 */
final readonly class ContentAbilities
{
    public function __construct(
        public bool $editAny,
        public bool $editOwn,
        public bool $publish,
    ) {}

    /**
     * The viewer's abilities for the type; none for nobody, or for a type
     * nothing declares.
     */
    public static function of(int $viewerId, string $type): self
    {
        return self::forUser($viewerId === 0 ? null : User::query()->find($viewerId), $type);
    }

    /**
     * The same, for someone already loaded, so a caller asking about several
     * types reads the person once.
     */
    public static function forUser(?User $viewer, string $type): self
    {
        $types = app(ContentTypeRegistry::class);

        if ($viewer === null || ! $types->has($type)) {
            return new self(false, false, false);
        }

        $definition = $types->get($type);

        return new self(
            $viewer->can($definition->ability('edit.any')),
            $viewer->can($definition->ability('edit.own')),
            $viewer->can($definition->ability('publish')),
        );
    }

    /**
     * ContentPolicy::update as a SQL boolean over one row: anyone who may edit
     * any item, or the item's author who may edit their own.
     *
     * @return array{0: string, 1: list<int>} the expression and its bindings
     */
    public function canEditSql(string $authorColumn, int $viewerId): array
    {
        return match (true) {
            $this->editAny => ['true', []],
            $this->editOwn => ["COALESCE({$authorColumn} = ?, false)", [$viewerId]],
            default => ['false', []],
        };
    }

    /**
     * ContentPolicy::publish over a row already read: the publish ability,
     * and either any item or the viewer's own.
     */
    public function canPublish(?int $authorId, int $viewerId): bool
    {
        return $this->publish && ($this->editAny || ($authorId !== null && $authorId === $viewerId));
    }
}
