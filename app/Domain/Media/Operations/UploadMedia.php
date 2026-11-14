<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\FileInspector;
use App\Domain\Media\InspectedFile;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaCounts;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaState;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\StorageKeys;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * Stores an uploaded file and creates its media item. Anyone who may upload
 * can do it, and the operator from the command line. The file is checked by
 * its content, and records the item it was uploaded for when its uploader
 * may edit it (I-553); an image's variants are made afterwards, on the queue.
 */
final class UploadMedia extends BaseOperation
{
    /** A content item's ID: 26 lower-case letters and digits (I-418). */
    private const string ITEM = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    public function __construct(
        private readonly FileInspector $inspector,
        private readonly SettingsReader $settings,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to upload files.');
        }

        if (! User::find($input->actorId())?->can('media.upload')) {
            throw new AuthorizationException('Not permitted to upload files.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UploadMediaInput $input */
        $file = $this->inspect($input);

        if (! $input->allowDuplicate) {
            $existing = Media::query()->where('sha256', $file->sha256)->value('id');

            if ($existing !== null) {
                throw new DuplicateMediaException((string) $existing);
            }
        }
    }

    protected function perform(OperationInput $input): Media
    {
        /** @var UploadMediaInput $input */
        $file = $this->inspect($input);
        $id = (string) Str::ulid();
        $name = StorageKeys::name($input->originalFilename);
        $path = StorageKeys::original($id, 1, $name, $file->extension);
        $disk = MediaDisks::forOriginal($file->kind);

        $stream = fopen($input->path, 'rb');
        $disk->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            $title = trim(pathinfo($input->originalFilename, PATHINFO_FILENAME));
            $uploadedFor = $this->uploadedFor($input);

            $media = Media::create([
                'id' => $id,
                'kind' => $file->kind,
                'mime_type' => $file->mimeType,
                'extension' => $file->extension,
                'original_filename' => mb_substr($input->originalFilename, 0, 255),
                'name' => $name,
                'original_path' => $path,
                'file_version' => 1,
                'size_bytes' => $file->sizeBytes,
                'sha256' => $file->sha256,
                'width' => $file->width,
                'height' => $file->height,
                'animated' => $file->animated,
                'title' => mb_substr($title === '' ? $name : $title, 0, 255),
                'variants' => [],
                'variant_set' => $file->kind === 'image' ? 0 : MediaVariants::SET,
                'processing' => $file->kind === 'image' ? 'pending' : 'ready',
                'uploaded_by' => $input->actorId() === null ? null : (int) $input->actorId(),
                'source_url' => $input->sourceUrl,
                'uploaded_for' => $uploadedFor,
            ]);

            MediaCounts::apply(null, MediaState::of($media));
        } catch (Throwable $exception) {
            // The row never existed, so neither may the file.
            $disk->delete($path);

            throw $exception;
        }

        if ($media->isImage()) {
            GenerateMediaVariants::dispatch($media->id, 1, JobActor::of($input->actorId()))->afterCommit();
        }

        // focal_x and focal_y are not in the create() payload above — they
        // come from the migration's column default — so the in-memory model
        // has them null until refreshed (the same trap as CreateTerm, B-065).
        $media->refresh();
        $actorId = $input->actorId();
        $this->notifyAfterCommit(MediaStored::class, fn (): MediaStored => new MediaStored(Payloads::media($media), false, $actorId));

        return $media;
    }

    /**
     * The item the upload was made for, when it names one its uploader may
     * edit, or, for the operator at the server's shell, any item; otherwise
     * none, and the file uploads all the same. The item is read under a share
     * lock, taken just before the row's insert, so deleting it waits for this
     * upload and then forgets it through the foreign key, never failing it (I-553).
     */
    private function uploadedFor(UploadMediaInput $input): ?string
    {
        if ($input->uploadedFor === null || preg_match(self::ITEM, $input->uploadedFor) !== 1) {
            return null;
        }

        // Content leaves out items in the trash: a file is never uploaded for one.
        $item = Content::query()->whereKey($input->uploadedFor)->sharedLock()->first();

        if ($item === null) {
            return null;
        }

        if ($input->actorId() === null) {
            return (string) $item->id;
        }

        $user = User::find($input->actorId());

        return $user !== null && Gate::forUser($user)->allows('update', $item) ? (string) $item->id : null;
    }

    private function inspect(UploadMediaInput $input): InspectedFile
    {
        return $this->inspector->inspect(
            $input->path,
            $input->originalFilename,
            $this->settings->int('media', 'max_upload_mb') * 1024 * 1024,
            $this->settings->int('media', 'max_megapixels') * 1_000_000,
        );
    }
}
