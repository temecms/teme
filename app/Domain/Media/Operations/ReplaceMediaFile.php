<?php

/**
 * Puts a new file of the same kind behind an existing item, so every use of
 * it follows (study K, §7). The file version rises, so the new file never
 * shares a URL with the old; an image keeps its old variants until the new
 * ones are recorded, and the old version's files are deleted after commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\FileInspector;
use App\Domain\Media\InspectedFile;
use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\ReplaceMediaFileInput;
use App\Domain\Media\StorageKeys;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final class ReplaceMediaFile extends BaseOperation
{
    public function __construct(
        private readonly FileInspector $inspector,
        private readonly SettingsReader $settings,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ReplaceMediaFileInput $input */
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('update', Media::withTrashed()->findOrFail($input->mediaId))) {
            throw new AuthorizationException('Not permitted to change this file.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReplaceMediaFileInput $input */
        $media = Media::withTrashed()->findOrFail($input->mediaId);

        if ($media->trashed()) {
            throw ValidationException::withMessages(['file' => __('Restore this file before replacing it.')]);
        }

        if ($this->inspect($input)->kind !== $media->kind) {
            throw ValidationException::withMessages(['file' => __('Replace it with a file of the same kind: an image with an image, a document with a document.')]);
        }
    }

    protected function perform(OperationInput $input): Media
    {
        /** @var ReplaceMediaFileInput $input */
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($input->mediaId);
        $file = $this->inspect($input);
        $version = $media->file_version + 1;
        $name = StorageKeys::name($input->originalFilename);
        $path = StorageKeys::original($media->id, $version, $name, $file->extension);
        $disk = MediaDisks::forOriginal($file->kind);
        $old = $media->original_path;

        $stream = fopen($input->path, 'rb');
        $disk->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            $media->forceFill([
                'mime_type' => $file->mimeType,
                'extension' => $file->extension,
                'original_filename' => mb_substr($input->originalFilename, 0, 255),
                'name' => $name,
                'original_path' => $path,
                'file_version' => $version,
                'size_bytes' => $file->sizeBytes,
                'sha256' => $file->sha256,
                'width' => $file->width,
                'height' => $file->height,
                'animated' => $file->animated,
                'processing' => $media->isImage() ? 'pending' : 'ready',
                'processing_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        // Its size shows at once; its new sizes when RecordMediaVariants has them (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));

        DeleteMediaFiles::dispatch([$media->isImage() ? 'originals' : 'public' => [$old]])->afterCommit();

        if ($media->isImage()) {
            GenerateMediaVariants::dispatch($media->id, $version, JobActor::of($input->actorId()))->afterCommit();
        }

        $media->refresh();
        $actorId = $input->actorId();
        $this->notifyAfterCommit(MediaStored::class, fn (): MediaStored => new MediaStored(Payloads::media($media), true, $actorId));

        return $media;
    }

    private function inspect(ReplaceMediaFileInput $input): InspectedFile
    {
        return $this->inspector->inspect(
            $input->path,
            $input->originalFilename,
            $this->settings->int('media', 'max_upload_mb') * 1024 * 1024,
            $this->settings->int('media', 'max_megapixels') * 1_000_000,
        );
    }
}
