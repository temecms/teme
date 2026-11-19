<?php

/**
 * Saves a Settings page from its one save bar (I-3437): the page's own
 * group, and the section another group lends it (General's brand colour,
 * Reading's home page), all or nothing. Each part is changed by its own
 * Operation, run inside this one.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Operations;

use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Design\Operations\ChangeBrandColour;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\SaveSettingsPageInput;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class SaveSettingsPage extends BaseOperation
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly UpdateSettings $updateSettings,
        private readonly ChangeBrandColour $changeBrandColour,
        private readonly ChangeAddresses $changeAddresses,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveSettingsPageInput $input */
        $definition = $this->registry->find($input->group->group);
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());
        // Every part's ability before anything is written, so a refusal writes nothing.
        $abilities = array_filter([
            $definition?->ability,
            $input->brandColour === null ? null : 'settings.design.manage',
            $input->homePage === null ? null : 'settings.addresses.manage',
        ]);

        if ($definition === null || $actor === null || array_any($abilities, fn (string $ability): bool => ! $actor->can($ability))) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        // Each part is validated by its own Operation, inside this one's transaction.
    }

    /**
     * @return array{recompute: bool} whether the home page moved every address
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SaveSettingsPageInput $input */
        $this->updateSettings->execute($input->group);

        if ($input->brandColour !== null) {
            try {
                $this->changeBrandColour->execute($input->brandColour);
            } catch (StaleSettingsException) {
                // Under the field: the page's own expected_version is the group's.
                throw ValidationException::withMessages([
                    'accent' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
                ]);
            }
        }

        $recompute = $input->homePage !== null && $this->changeAddresses->execute($input->homePage)['recompute'];

        return ['recompute' => $recompute];
    }
}
