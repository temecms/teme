<?php

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\ManageApi\ApiMedia;
use App\Domain\ManageApi\ManageApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * POST /api/manage/v1/media stores a file, sent as multipart (`file`) or
 * as the raw body named by its Content-Disposition; PATCH changes its alt
 * text, caption, title and credit. Both need media.write, and the key's
 * person's own permission through the Operations.
 */
final class MediaController
{
    /** The details PATCH may change. */
    private const array DETAILS = ['title', 'alt', 'caption', 'credit'];

    public function store(Request $request, ApiMedia $media): JsonResponse
    {
        $caller = ManageRequest::caller($request);
        $allowDuplicate = $request->boolean('allow_duplicate');
        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            if (! $file->isValid()) {
                throw ManageApiProblem::invalid(['file' => __('The file didn’t arrive whole. Send it again.')]);
            }

            return ManageAnswer::data($media->upload($caller, (string) $file->getRealPath(), $file->getClientOriginalName(), $allowDuplicate), 201);
        }

        $filename = self::filename((string) $request->headers->get('Content-Disposition'));

        if ($filename === null) {
            throw ManageApiProblem::invalid(['file' => __('Send the file as multipart form data in file, or as the body with a Content-Disposition header naming it.')]);
        }

        if ((int) $request->headers->get('Content-Length') > $media->maxBytes()) {
            throw ManageApiProblem::invalid(['file' => __('The file is larger than the :size MB limit.', ['size' => intdiv($media->maxBytes(), 1024 * 1024)])]);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'teme-api-media-');

        try {
            $target = fopen($path, 'wb');
            $source = $request->getContent(true);

            if ($target === false || ! is_resource($source)) {
                throw ManageApiProblem::invalid(['file' => __('The file didn’t arrive whole. Send it again.')]);
            }

            stream_copy_to_stream($source, $target, $media->maxBytes() + 1);
            fclose($target);

            return ManageAnswer::data($media->upload($caller, $path, $filename, $allowDuplicate), 201);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function update(Request $request, string $media, ApiMedia $files): JsonResponse
    {
        $changes = [];
        $refused = [];

        foreach (self::DETAILS as $detail) {
            if ($request->exists($detail)) {
                $value = $request->input($detail);

                if ($value !== null && ! is_string($value)) {
                    $refused[$detail] = __(':name must be text.', ['name' => $detail]);
                }

                $changes[$detail] = is_string($value) ? $value : null;
            }
        }

        if ($refused !== []) {
            throw ManageApiProblem::invalid($refused);
        }

        /** @var array{title?: string, alt?: string|null, caption?: string|null, credit?: string|null} $changes */
        return ManageAnswer::data($files->update(ManageRequest::caller($request), $media, $changes));
    }

    /**
     * The file name a Content-Disposition header gives, its RFC 5987 form
     * first; null when it names none.
     */
    private static function filename(string $disposition): ?string
    {
        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $disposition, $match) === 1) {
            $name = rawurldecode(trim($match[1]));
        } elseif (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $disposition, $match) === 1) {
            $name = stripcslashes($match[1]);
        } elseif (preg_match('/filename\s*=\s*([^;\s]+)/i', $disposition, $match) === 1) {
            $name = $match[1];
        } else {
            return null;
        }

        $name = trim(basename(str_replace('\\', '/', $name)));

        return $name === '' || $name === '.' ? null : $name;
    }
}
