<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Input\RenameNavigationInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * Renames a menu. Its handle stays as it was made.
 */
final class RenameNavigation extends BaseOperation
{
    use ManagesNavigation;

    protected function authorize(OperationInput $input): void
    {
        /** @var RenameNavigationInput $input */
        $this->authorizeNavigation($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RenameNavigationInput $input */
        $this->validateName($input->name);
    }

    protected function perform(OperationInput $input): Navigation
    {
        /** @var RenameNavigationInput $input */
        $navigation = Navigation::query()->lockForUpdate()->findOrFail($input->id);
        $navigation->update(['name' => trim($input->name)]);

        return $navigation;
    }
}
