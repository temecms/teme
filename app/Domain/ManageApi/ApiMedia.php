<?php

/**
 * The management API's media: a file stored through UploadMedia and its
 * details changed through UpdateMediaDetails, both as the key's person,
 * answered with addresses generated from the current site address, never
 * stored (rule 9).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Operations\UploadMedia;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final readonly class ApiMedia
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * The largest file the media settings allow, in bytes.
     */
    public function maxBytes(): int
    {
        return $this->settings->int('media', 'max_upload_mb') * 1024 * 1024;
    }

    /**
     * Stores the file at $path, uploaded as $filename, as the caller's person.
     *
     * @return array<string, mixed> the media item as the API answers it
     */
    public function upload(ApiCaller $caller, string $path, string $filename, bool $allowDuplicate): array
    {
        try {
            $media = app(UploadMedia::class)->execute(new UploadMediaInput($path, $filename, $caller->personId, $allowDuplicate));
        } catch (Throwable $refusal) {
            throw ManageApiProblem::fromRefusal($refusal);
        }

        return self::shape($media);
    }

    /**
     * Changes the details sent, keeping the others as they are.
     *
     * @param  array{title?: string, alt?: string|null, caption?: string|null, credit?: string|null}  $changes
     *
     * @return array<string, mixed> the media item as the API answers it
     */
    public function update(ApiCaller $caller, string $id, array $changes): array
    {
        $media = preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $id) === 1 ? Media::query()->find($id) : null;

        if ($media === null) {
            throw ManageApiProblem::notFound(ManageApiWords::noMedia($id));
        }

        try {
            $media = app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput(
                mediaId: (string) $media->id,
                actorId: $caller->personId,
                title: array_key_exists('title', $changes) ? (string) $changes['title'] : $media->title,
                alt: array_key_exists('alt', $changes) ? $changes['alt'] : $media->alt,
                caption: array_key_exists('caption', $changes) ? $changes['caption'] : $media->caption,
                credit: array_key_exists('credit', $changes) ? $changes['credit'] : $media->credit,
                focalX: $media->focal_x,
                focalY: $media->focal_y,
            ));
        } catch (Throwable $refusal) {
            throw ManageApiProblem::fromRefusal($refusal);
        }

        return self::shape($media);
    }

    /**
     * Returns the media item as the API answers it: its ID, details, type,
     * public address (null while an image's sizes are being made) and state.
     *
     * @return array{id: string, title: string, alt: string|null, caption: string|null, credit: string|null, mime_type: string, url: string|null, processing: string}
     */
    public static function shape(Media $media): array
    {
        $url = $media->isImage()
            ? MediaUrls::largest((array) $media->variants, (string) $media->id)
            : MediaDisks::public()->url($media->original_path);

        if ($url !== null && str_starts_with($url, '/')) {
            $url = rtrim(url('/'), '/').$url;
        }

        return [
            'id' => (string) $media->id,
            'title' => (string) $media->title,
            'alt' => $media->alt,
            'caption' => $media->caption,
            'credit' => $media->credit,
            'mime_type' => (string) $media->mime_type,
            'url' => $url,
            'processing' => (string) $media->processing,
        ];
    }
}
