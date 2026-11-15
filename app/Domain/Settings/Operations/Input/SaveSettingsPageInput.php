<?php

declare(strict_types=1);

namespace App\Domain\Settings\Operations\Input;

use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use Teme\Contracts\Operations\OperationInput;

/**
 * What `SaveSettingsPage` needs: the page's own group, and the section
 * another group lends the page, when the page sent it.
 */
final readonly class SaveSettingsPageInput implements OperationInput
{
    public function __construct(
        public UpdateSettingsInput $group,
        public ?ChangeBrandColourInput $brandColour = null,
        public ?ChangeAddressesInput $homePage = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->group->actorId();
    }
}
