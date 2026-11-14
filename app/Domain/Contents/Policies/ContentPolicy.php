<?php

declare(strict_types=1);

namespace App\Domain\Contents\Policies;

use App\Domain\Contents\Models\Content;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;

/**
 * Decides who may create, edit and publish content: anyone holding the
 * ability the item's type names for that action, and authors only for their
 * own work (I-413). The content Operations ask it before they write.
 */
final class ContentPolicy
{
    /**
     * Whether the user may create an item of $type. An unregistered type is
     * judged by the global ability, and validation then refuses it.
     */
    public function create(User $user, ?string $type = null): bool
    {
        return $user->can($this->ability($type ?? '', 'create'));
    }

    /**
     * Whether the user may edit the item: anyone's with the type's edit.any
     * ability, their own with edit.own. ContentAbilities restates this rule
     * (and publish's) as SQL for the lists; if it changes here, it changes
     * there too, and OthersWorkTest compares the two.
     */
    public function update(User $user, Content $content): bool
    {
        if ($user->can($this->ability($content->type, 'edit.any'))) {
            return true;
        }

        return $user->can($this->ability($content->type, 'edit.own'))
            && $content->author_id === $user->id;
    }

    /**
     * Whether the user may trash, restore or permanently delete the item:
     * anyone's with the type's delete.any ability, their own with delete.own.
     */
    public function delete(User $user, Content $content): bool
    {
        if ($user->can($this->ability($content->type, 'delete.any'))) {
            return true;
        }

        return $user->can($this->ability($content->type, 'delete.own'))
            && $content->author_id === $user->id;
    }

    /**
     * Whether the user may publish the item: the type's publish ability, and
     * either edit.any or authorship. ContentAbilities::canPublish() restates
     * it for the lists; change both together.
     */
    public function publish(User $user, Content $content): bool
    {
        if (! $user->can($this->ability($content->type, 'publish'))) {
            return false;
        }

        return $user->can($this->ability($content->type, 'edit.any'))
            || $content->author_id === $user->id;
    }

    private function ability(string $type, string $action): string
    {
        return app(ContentTypeRegistry::class)->ability($type, $action);
    }
}
