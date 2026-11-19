<?php

/**
 * Moves an item to the trash. Its files stay, so restoring brings every use
 * back unchanged (decision 5B); pages treat it as missing meanwhile. Anyone
 * who may remove the item can do it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\MediaState;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class TrashMedia extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('delete', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to remove this file.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var MediaActionInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);

        if ($media->trashed()) {
            return null;
        }

        $before = MediaState::of($media);
        $media->delete();
        MediaCounts::apply($before, MediaState::of($media));

        // A trashed image draws as nothing (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));

        return null;
    }
}
