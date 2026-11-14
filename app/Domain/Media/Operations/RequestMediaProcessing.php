<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Queues an image's variants to be made again — the library's Retry. Anyone
 * who may change the item can ask.
 */
final class RequestMediaProcessing extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('update', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to change this file.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        if (! Media::withTrashed()->findOrFail($input->mediaId)->isImage()) {
            throw ValidationException::withMessages(['media' => __('Only images are processed.')]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var MediaActionInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);
        $media->forceFill(['processing' => 'pending', 'processing_error' => null])->save();

        GenerateMediaVariants::dispatch($media->id, $media->file_version, JobActor::of($input->actorId()))->afterCommit();

        return null;
    }
}
