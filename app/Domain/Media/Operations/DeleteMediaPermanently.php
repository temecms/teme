<?php

/**
 * Deletes a trashed item for good; its files are deleted after commit, by the
 * paths its row recorded. Only items already in the trash, by someone who may
 * remove them, or by the operator's scheduled purge.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\MediaState;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteMediaPermanently extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to delete this file.');
        }

        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('delete', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to delete this file.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        if (! Media::withTrashed()->findOrFail($input->mediaId)->trashed()) {
            throw ValidationException::withMessages(['media' => __('Move it to the trash first.')]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var MediaActionInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);

        $files = [
            'originals' => $media->isImage() ? [$media->original_path] : [],
            'public' => [...($media->isImage() ? [] : [$media->original_path]), ...array_column($media->variants, 'path')],
        ];

        MediaCounts::apply(MediaState::of($media), null);
        $media->forceDelete();

        DeleteMediaFiles::dispatch($files)->afterCommit();

        return null;
    }
}
