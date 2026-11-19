<?php

/**
 * Brings one downloaded file from an imported site into the media library
 * (I-338): the item that already holds the same bytes, whoever made it, or
 * a new item stored through UploadMedia with the old site's title, alt text,
 * caption, uploader and date. Only an import's actor may call it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\ImportMediaInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ImportMedia extends BaseOperation
{
    use AuthorizesImports;

    public function __construct(private readonly UploadMedia $upload) {}

    /**
     * What an import wrote to an item, so a later run can tell what a
     * person has changed here since: each text's SHA-1, and the file's
     * SHA-256. Never the text itself.
     *
     * @return array{title: string, alt: string, caption: string, file: string}
     */
    public static function fingerprint(Media $media): array
    {
        return [
            'title' => sha1($media->title),
            'alt' => sha1((string) $media->alt),
            'caption' => sha1((string) $media->caption),
            'file' => $media->sha256,
        ];
    }

    /**
     * The checks an imported item's file and details meet, at the limits
     * UpdateMediaDetails keeps, counted in characters.
     *
     * @throws ValidationException
     */
    public static function validateDetails(?string $path, string $title, ?string $alt, ?string $caption): void
    {
        if ($path !== null && ! is_file($path)) {
            throw ValidationException::withMessages(['file' => 'The downloaded file is missing.']);
        }

        Validator::make(
            ['title' => trim($title), 'alt' => $alt, 'caption' => $caption],
            [
                'title' => ['required', 'string', 'max:255'],
                'alt' => ['nullable', 'string', 'max:1000'],
                'caption' => ['nullable', 'string', 'max:2000'],
            ],
            [
                'title.required' => 'Give the item a title of 255 characters or fewer.',
                'title.max' => 'Give the item a title of 255 characters or fewer.',
                'alt.max' => 'Keep the alt text to 1,000 characters.',
                'caption.max' => 'Keep the caption to 2,000 characters.',
            ],
        )->validate();
    }

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportMediaInput $input */
        self::validateDetails($input->path, $input->title, $input->alt, $input->caption);

        if ($input->uploadedBy !== null && ! User::query()->whereKey($input->uploadedBy)->exists()) {
            throw ValidationException::withMessages(['uploaded_by' => 'The person who uploaded it isn’t here.']);
        }
    }

    /**
     * @return array{mediaId: string, created: bool, written: array{title: string, alt: string, caption: string, file: string}|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportMediaInput $input */
        $sha = (string) hash_file('sha256', $input->path);

        // Held until the import's transaction ends, so two workers, two lists or two runs make one item of these bytes (I-338).
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["teme.media.sha256:{$sha}"]);

        $existing = Media::query()->where('sha256', $sha)->orderBy('created_at')->orderBy('id')->value('id');

        if ($existing !== null) {
            return ['mediaId' => (string) $existing, 'created' => false, 'written' => null];
        }

        $media = $this->upload->execute(new UploadMediaInput($input->path, $input->filename, $input->actorId(), true, $input->sourceUrl));
        $disk = MediaDisks::forOriginal($media->kind);
        $stored = $media->original_path;

        // UploadMedia stored the file inside this transaction: if the object's import rolls back, the file goes too.
        DB::afterRollBack(fn () => $disk->delete($stored));

        $media->forceFill([
            'title' => trim($input->title),
            'alt' => $input->alt === null || trim($input->alt) === '' ? null : $input->alt,
            'caption' => $input->caption === null || trim($input->caption) === '' ? null : $input->caption,
            'uploaded_by' => $input->uploadedBy === null ? null : (int) $input->uploadedBy,
            'created_at' => $input->uploadedAt ?? $media->created_at,
        ])->save();

        return ['mediaId' => (string) $media->id, 'created' => true, 'written' => self::fingerprint($media)];
    }
}
