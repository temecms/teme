<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Media\Images\ImageEngine;
use finfo;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether a file may be stored, from its content alone: an accepted
 * type, within the size limit, and for images one the driver can read, whole, and
 * within the pixel limit, checked before the image is decoded.
 */
final class FileInspector
{
    public function __construct(private readonly ImageEngine $engine) {}

    public function inspect(string $path, string $originalFilename, int $maxBytes, int $maxPixels): InspectedFile
    {
        $size = (int) filesize($path);

        if ($size === 0) {
            $this->refuse(__('The file is empty.'));
        }

        if ($size > $maxBytes) {
            $this->refuse(__('The file is larger than the :size MB limit.', ['size' => intdiv($maxBytes, 1024 * 1024)]));
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $type = FileTypes::for($mime, $originalFilename);

        if ($type === null) {
            $this->refuse(__('Files of this type cannot be uploaded.'));
        }

        [$width, $height, $animated] = [null, null, false];

        if ($type['kind'] === 'image') {
            if (! $this->engine->canDecode($mime)) {
                $this->refuse(__('This server cannot read :format images. Convert it to JPEG or PNG and upload it again.', ['format' => strtoupper($type['extension'])]));
            }

            // getimagesize reads only the header, so the pixel limit is
            // checked before anything is decoded.
            $header = @getimagesize($path);

            if ($header !== false && $maxPixels < $header[0] * $header[1]) {
                $this->refuse(__('The image is larger than the :megapixels megapixel limit.', ['megapixels' => intdiv($maxPixels, 1_000_000)]));
            }

            $read = $this->engine->inspect($path);

            if ($read === null) {
                $this->refuse(__('This file looks like an image but can’t be read. It may be damaged. Open it in an image editor, save it again and upload it.'));
            }

            [$width, $height, $animated] = [$read['width'], $read['height'], $read['animated']];

            if ($width * $height > $maxPixels) {
                $this->refuse(__('The image is larger than the :megapixels megapixel limit.', ['megapixels' => intdiv($maxPixels, 1_000_000)]));
            }
        }

        return new InspectedFile($mime, $type['kind'], $type['extension'], $size, (string) hash_file('sha256', $path), $width, $height, $animated);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
