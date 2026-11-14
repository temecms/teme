<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\Downloads\DownloadRefused;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\Exceptions\DuplicateMediaException;
use App\Domain\Media\Operations\Input\DownloadImageInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Brings an image from another site into the media library, for the
 * editor's Import images (I-453): who asks is checked before anything is
 * fetched; the download happens before the transaction; UploadMedia stores
 * it with where it came from; a file the library already has gives back
 * that item instead.
 */
final class DownloadImage extends BaseOperation
{
    /** What validate() downloaded, for perform() to store. */
    private ?DownloadedFile $file = null;

    public function __construct(
        private readonly FileDownloader $downloader,
        private readonly UploadMedia $upload,
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
        /** @var DownloadImageInput $input */
        Validator::make(
            ['address' => trim($input->address)],
            ['address' => ['required', 'string', 'max:2048']],
            ['address.required' => 'Give the image’s address.', 'address.max' => 'That address is too long.'],
        )->validate();

        try {
            $this->file = $this->downloader->download($input->address, imagesOnly: true);
        } catch (DownloadRefused $refusal) {
            throw ValidationException::withMessages(['address' => $refusal->sentence()]);
        }
    }

    /**
     * @return array{mediaId: string, created: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var DownloadImageInput $input */
        $file = $this->file ?? throw new LogicException('DownloadImage downloads the image in validate().');

        try {
            $media = $this->upload->execute(new UploadMediaInput($file->path, $file->name, $input->actorId(), false, $file->sourceUrl, $input->uploadedFor));

            return ['mediaId' => (string) $media->id, 'created' => true];
        } catch (DuplicateMediaException $duplicate) {
            return ['mediaId' => $duplicate->existingId, 'created' => false];
        } catch (ValidationException $refusal) {
            // The library's own refusal, too many pixels or a format the server can't read, under the address.
            throw ValidationException::withMessages(['address' => $refusal->errors()['file'][0] ?? 'The media library refused this file.']);
        } finally {
            $file->release();
            $this->file = null;
        }
    }
}
