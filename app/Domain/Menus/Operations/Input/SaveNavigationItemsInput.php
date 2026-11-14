<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What SaveNavigationItems needs: which menu, its whole new tree, and who is
 * asking. Each item is `{kind, target_id?, url?, label?, opens_new_tab?,
 * children?}`, as the navigation editor sends it.
 */
final readonly class SaveNavigationItemsInput implements OperationInput
{
    /**
     * @param  array<mixed>  $tree
     */
    public function __construct(
        public string $navigationId,
        public array $tree,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
