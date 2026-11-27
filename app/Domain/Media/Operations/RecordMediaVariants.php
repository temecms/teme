<?php

/**
 * Records what processing produced for one file version of an image: its
 * variants, or the error that stopped them. Called by the queue job as the
 * person whose change caused it, or by the operator's regeneration. Refuses
 * a result for a file that has since been replaced. An actor who may not
 * change the image may still record its variants for an item they may edit
 * that uses it as its featured or social image.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Media\Exceptions\StaleMediaException;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Queries\ShareImageUse;
use App\Domain\Media\Queries\SiteIconUse;
use App\Domain\Media\RecordedVariants;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class RecordMediaVariants extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RecordMediaVariantsInput $input */
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to change this file.');
        }

        $user = User::find($input->actorId());
        $media = Media::withTrashed()->findOrFail($input->mediaId);

        if ($user !== null && Gate::forUser($user)->allows('update', $media)) {
            return;
        }

        // Whoever used the image as an item's featured or social image may
        // have its variants made, the share images among them (I-855).
        $item = $input->usedBy === null ? null : Content::query()->find($input->usedBy);

        if ($user !== null && $item !== null && Gate::forUser($user)->allows('update', $item) && ShareImageUse::usedBy((string) $media->id, (string) $item->id)) {
            return;
        }

        throw new AuthorizationException('Not permitted to change this file.');
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): RecordedVariants
    {
        /** @var RecordMediaVariantsInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);

        if ($media->file_version !== $input->fileVersion) {
            throw new StaleMediaException("Media {$media->id} is now at version {$media->file_version}.");
        }

        if ($input->variants === null) {
            $media->forceFill(['processing' => 'failed', 'processing_error' => $input->error])->save();

            return new RecordedVariants([]);
        }

        $kept = array_column($input->variants, 'path');
        $obsolete = array_values(array_diff(array_column($media->variants, 'path'), $kept));

        $media->forceFill([
            'variants' => $input->variants,
            'variant_set' => $input->variantSet,
            'processing' => 'ready',
            'processing_error' => null,
        ])->save();

        // A remake that began before the image was first used so lands
        // without its share images: make them now (I-855). Same for the
        // site icon's favicon sizes (issue 388).
        $needsShare = ! isset($input->variants['social']) && ShareImageUse::wanted((string) $media->id);
        $needsIcon = ! isset($input->variants['icon-32']) && SiteIconUse::wanted((string) $media->id);

        if ($needsShare || $needsIcon) {
            GenerateMediaVariants::dispatch((string) $media->id, (int) $media->file_version, JobActor::of($input->actorId()), $input->usedBy)->afterCommit();
        }

        // Each size is in the srcset of every page that draws it (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));

        return new RecordedVariants($obsolete);
    }
}
