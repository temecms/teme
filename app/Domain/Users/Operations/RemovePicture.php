<?php

/**
 * Clears a person's picture, so their initials show. You may clear your
 * own; someone who manages users may clear anyone's
 * (docs/proposals/people-and-account.md). The image stays in Media.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\RemovePictureInput;
use App\Models\User;
use Teme\Contracts\Operations\OperationInput;

final class RemovePicture extends BaseOperation
{
    use ManagesUsers;

    protected function authorize(OperationInput $input): void
    {
        /** @var RemovePictureInput $input */
        if ($input->actorId() !== null && $input->actorId() === $input->userId) {
            return;
        }

        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void {}

    /** @return array{name: string, removed: bool} */
    protected function perform(OperationInput $input): array
    {
        /** @var RemovePictureInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $removed = $user->picture_media_id !== null;
        $user->picture_media_id = null;
        $user->save();

        return ['name' => $user->name, 'removed' => $removed];
    }
}
