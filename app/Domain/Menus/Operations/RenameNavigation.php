<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Input\RenameNavigationInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * Renames a menu, and sets its language when one is given
 * (docs/proposals/multilingual-screens.md, 5). Its handle stays as it was made.
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
        $this->validateLanguage($input->language);
    }

    protected function perform(OperationInput $input): Navigation
    {
        /** @var RenameNavigationInput $input */
        $navigation = Navigation::query()->lockForUpdate()->findOrFail($input->id);
        $navigation->update(['name' => trim($input->name)] + ($input->language === null ? [] : ['language' => self::storedLanguage($input->language)]));

        return $navigation;
    }
}
