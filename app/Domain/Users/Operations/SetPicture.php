<?php

/**
 * Sets your own picture to an image in Media (I-3410). Only you choose it:
 * an administrator may remove another person's picture, never choose one.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;

final class SetPicture extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SetPictureInput $input */
        if ($input->actorId() === null || $input->actorId() !== $input->userId) {
            throw new AuthorizationException('You may only change your own picture.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetPictureInput $input */
        Validator::make(
            ['media' => $input->mediaId],
            ['media' => ['required', 'string', Rule::exists('media', 'id')->where('kind', 'image')->whereNull('deleted_at')]],
            ['media.required' => __('Choose an image from Media.'), 'media.exists' => __('Choose an image from Media.')],
        )->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var SetPictureInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $user->picture_media_id = $input->mediaId;
        $user->save();

        return null;
    }
}
