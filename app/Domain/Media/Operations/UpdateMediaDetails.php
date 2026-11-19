<?php

/**
 * Changes an item's title, default alt text, caption, credit, licence and focal
 * point. Anyone who may change the item can do it. The defaults apply at
 * once wherever the item is used without an override (decision 4A); a moved
 * focal point remakes the variants.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class UpdateMediaDetails extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var UpdateMediaDetailsInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('update', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to change this file.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateMediaDetailsInput $input */
        Validator::make(
            ['title' => trim($input->title), 'alt' => $input->alt, 'caption' => $input->caption, 'credit' => $input->credit, 'licence' => $input->licence, 'focal_x' => $input->focalX, 'focal_y' => $input->focalY],
            [
                'title' => ['required', 'string', 'max:255'],
                'alt' => ['nullable', 'string', 'max:1000'],
                'caption' => ['nullable', 'string', 'max:2000'],
                'credit' => ['nullable', 'string', 'max:255'],
                'licence' => ['nullable', 'string', 'max:255'],
                'focal_x' => ['required', 'integer', 'between:0,100'],
                'focal_y' => ['required', 'integer', 'between:0,100'],
            ],
        )->validate();
    }

    protected function perform(OperationInput $input): Media
    {
        /** @var UpdateMediaDetailsInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);
        $blank = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        $focalX = $input->focalX ?? throw new LogicException('validate() guarantees a focal_x.');
        $focalY = $input->focalY ?? throw new LogicException('validate() guarantees a focal_y.');
        $focusMoved = $media->focal_x !== $focalX || $media->focal_y !== $focalY;

        $media->forceFill([
            'title' => trim($input->title),
            'alt' => $blank($input->alt),
            'caption' => $blank($input->caption),
            'credit' => $blank($input->credit),
            'focal_x' => $focalX,
            'focal_y' => $focalY,
        ]);

        // Null leaves the licence as it is: most callers don't know of it (I-236).
        if ($input->licence !== null) {
            $media->licence = $blank($input->licence);
        }

        if ($focusMoved && $media->isImage()) {
            $media->processing = 'pending';
            GenerateMediaVariants::dispatch($media->id, $media->file_version, JobActor::of($input->actorId()))->afterCommit();
        }

        $shownChanged = $media->isDirty();
        $media->save();

        // A page draws its alt text, title and focal point (I-263).
        if ($shownChanged) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));
        }

        return $media->refresh();
    }
}
