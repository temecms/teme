<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Media\FileInspector;
use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateImportedMediaInput;
use App\Domain\Media\StorageKeys;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Brings an item an earlier import made up to date with WordPress (I-338):
 * its title, alt text and caption, and its file when WordPress's changed,
 * each only while it is still what the import wrote, so a person's change
 * here is never taken back. Only an import's actor may call it.
 */
final class UpdateImportedMedia extends BaseOperation
{
    use AuthorizesImports;

    /** The details an import writes, by their names in the fingerprint. */
    private const array FIELDS = ['title', 'alt', 'caption'];

    public function __construct(
        private readonly FileInspector $inspector,
        private readonly SettingsReader $settings,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateImportedMediaInput $input */
        $media = Media::withTrashed()->find($input->mediaId);

        if ($media === null) {
            throw ValidationException::withMessages(['media' => 'The item to update no longer exists.']);
        }

        if ($media->trashed()) {
            throw ValidationException::withMessages(['media' => 'Restore this item before the import changes it.']);
        }

        foreach ([...self::FIELDS, 'file'] as $key) {
            if (! is_string($input->written[$key] ?? null)) {
                throw ValidationException::withMessages(['written' => 'The import’s record of this item couldn’t be read.']);
            }
        }

        if ($input->path !== null && trim((string) $input->filename) === '') {
            throw ValidationException::withMessages(['file' => 'The downloaded file is missing.']);
        }

        ImportMedia::validateDetails($input->path, $input->title, $input->alt, $input->caption);
    }

    /**
     * @return array{written: array{title: string, alt: string, caption: string, file: string}, kept: list<string>, replaced: bool, refused: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateImportedMediaInput $input */
        $media = Media::query()->lockForUpdate()->findOrFail($input->mediaId);
        $now = ImportMedia::fingerprint($media);
        $wanted = ['title' => trim($input->title), 'alt' => self::blank($input->alt), 'caption' => self::blank($input->caption)];
        /** @var array{title: string, alt: string, caption: string, file: string} $written */
        $written = $input->written;
        $kept = [];
        $changes = [];

        foreach (self::FIELDS as $field) {
            $current = (string) $media->getAttribute($field);

            if ($now[$field] !== $input->written[$field]) {
                // A person changed it here: theirs stays, and so does the record a later run compares with.
                if ($current !== (string) $wanted[$field]) {
                    $kept[] = $field;
                }

                continue;
            }

            if ($current !== (string) $wanted[$field]) {
                $changes[$field] = $wanted[$field];
            }

            $written[$field] = sha1((string) $wanted[$field]);
        }

        $replaced = false;
        $refused = false;

        if ($input->path !== null) {
            if ($now['file'] !== $input->written['file']) {
                $kept[] = 'file';
            } else {
                $outcome = $this->replace($media, $input);
                $replaced = $outcome === true;
                $refused = $outcome === null;
                $written['file'] = $media->sha256;
            }
        }

        if ($changes !== []) {
            $media->forceFill($changes)->save();
        }

        if ($changes !== [] || $replaced) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::MEDIA, (string) $media->id, [PageTags::media((string) $media->id)]));
        }

        return ['written' => $written, 'kept' => $kept, 'replaced' => $replaced, 'refused' => $refused];
    }

    /**
     * Puts WordPress's new file behind the item, as ReplaceMediaFile does
     * (its authorize() refuses the operator, so its body is copied): a new
     * file version, so it never shares an address with the old; the old
     * file deleted after commit, the new one after a rollback. True when
     * replaced; false when the bytes are the same; null when the file is
     * another kind, which WordPress's image editor never makes and a person
     * decides.
     */
    private function replace(Media $media, UpdateImportedMediaInput $input): ?bool
    {
        $filename = (string) $input->filename;
        $file = $this->inspector->inspect(
            (string) $input->path,
            $filename,
            $this->settings->int('media', 'max_upload_mb') * 1024 * 1024,
            $this->settings->int('media', 'max_megapixels') * 1_000_000,
        );

        if ($file->kind !== $media->kind) {
            return null;
        }

        if ($file->sha256 === $media->sha256) {
            return false;
        }

        $version = $media->file_version + 1;
        $name = StorageKeys::name($filename);
        $path = StorageKeys::original($media->id, $version, $name, $file->extension);
        $disk = MediaDisks::forOriginal($file->kind);
        $old = $media->original_path;
        $stream = fopen((string) $input->path, 'rb');
        $disk->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        DB::afterRollBack(fn () => $disk->delete($path));

        $media->forceFill([
            'mime_type' => $file->mimeType,
            'extension' => $file->extension,
            'original_filename' => mb_substr($filename, 0, 255),
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
            'source_url' => $input->sourceUrl,
        ])->save();

        DeleteMediaFiles::dispatch([$media->isImage() ? 'originals' : 'public' => [$old]])->afterCommit();

        if ($media->isImage()) {
            GenerateMediaVariants::dispatch($media->id, $version, JobActor::of($input->actorId()))->afterCommit();
        }

        return true;
    }

    private static function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
