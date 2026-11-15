<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Hooks\Payloads;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Operations\UploadMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\RequestFailed;
use Teme\Contracts\Media\MediaRef;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * A plugin's media.write door: fetches a file from an agreed host through
 * the same outbound rules as http, then stores it with UploadMedia
 * (docs/proposals/plugin-content-write.md).
 */
final readonly class PluginMediaWriter implements MediaWriter
{
    /**
     * @param  list<string>  $hosts  the hosts this plugin's consent covers
     */
    public function __construct(
        private array $hosts,
        private HostResolver $resolver,
    ) {}

    public function fromAddress(string $url, string $actingPersonId, ?string $alt = null, ?string $title = null): MediaRef
    {
        ActingPerson::require($actingPersonId);

        if ($alt !== null && mb_strlen($alt) > 1000) {
            throw new WriteRefused(__('Alt text may be at most 1000 characters.'));
        }

        if ($title !== null && mb_strlen($title) > 255) {
            throw new WriteRefused(__('A title may be at most 255 characters.'));
        }

        $http = new PluginHttp($this->hosts, $this->resolver);
        $temp = tempnam(sys_get_temp_dir(), 'teme-plugin-media-');

        if ($temp === false) {
            throw new WriteRefused(__('Teme couldn’t make a temporary file for a download.'));
        }

        try {
            try {
                $answer = $http->get($url);
            } catch (HostRefused|AnswerTooLarge|RequestFailed $failure) {
                throw new WriteRefused($failure->getMessage(), previous: $failure);
            }

            if ($answer->status < 200 || $answer->status >= 300) {
                throw new WriteRefused(__('The request to fetch that file failed.'));
            }

            $maxBytes = app(SettingsReader::class)->int('media', 'max_upload_mb') * 1024 * 1024;

            if (strlen($answer->body) > $maxBytes) {
                throw new WriteRefused(__('The file is larger than the :size MB limit.', [
                    'size' => intdiv($maxBytes, 1024 * 1024),
                ]));
            }

            if (file_put_contents($temp, $answer->body) === false) {
                throw new WriteRefused(__('Teme couldn’t save the downloaded file.'));
            }

            $name = $this->filename($url, $title);

            try {
                $media = DB::transaction(function () use ($temp, $name, $actingPersonId, $url, $alt, $title) {
                    $media = app(UploadMedia::class)->execute(new UploadMediaInput(
                        path: $temp,
                        originalFilename: $name,
                        actorId: $actingPersonId,
                        allowDuplicate: true,
                        sourceUrl: $url,
                    ));

                    if ($alt !== null || $title !== null) {
                        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput(
                            mediaId: (string) $media->id,
                            actorId: $actingPersonId,
                            title: $title ?? (string) $media->title,
                            alt: $alt,
                            caption: $media->caption,
                            credit: $media->credit,
                            focalX: $media->focal_x,
                            focalY: $media->focal_y,
                            licence: $media->licence,
                        ));
                        $media->refresh();
                    }

                    return $media;
                });
            } catch (Throwable $failure) {
                $this->refuse($failure);
            }

            return Payloads::media($media);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function filename(string $url, ?string $title): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = is_string($path) ? basename($path) : '';

        if ($base !== '' && $base !== '/' && $base !== '.') {
            return $base;
        }

        return ($title !== null && trim($title) !== '' ? trim($title) : 'download').'.bin';
    }

    /**
     * @throws WriteRefused
     */
    private function refuse(Throwable $failure): never
    {
        if ($failure instanceof WriteRefused) {
            throw $failure;
        }

        if ($failure instanceof AuthorizationException) {
            throw new WriteRefused($failure->getMessage() !== '' ? $failure->getMessage() : 'Not permitted.', previous: $failure);
        }

        if ($failure instanceof ValidationException) {
            $first = collect($failure->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : 'The change was refused.', previous: $failure);
        }

        throw $failure;
    }
}
