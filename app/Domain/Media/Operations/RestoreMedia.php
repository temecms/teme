<?php

/**
 * Brings an item back from the trash, exactly as it was, so every page that
 * used it shows it again. Anyone who may remove the item can do it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\MediaState;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Media\MediaRestored;
use Teme\Contracts\Operations\OperationInput;

final class RestoreMedia extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MediaActionInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('delete', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to restore this file.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var MediaActionInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);

        if (! $media->trashed()) {
            return null;
        }

        $before = MediaState::of($media);
        $media->restore();
        MediaCounts::apply($before, MediaState::of($media));
        $this->notifyAfterCommit(MediaRestored::class, fn (): MediaRestored => new MediaRestored(Payloads::media($media), $input->actorId()));

        // A restored image draws again (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));

        return null;
    }
}
