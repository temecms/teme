<?php

/**
 * The file types Teme accepts, by the media type PHP's fileinfo detects in
 * the content, with the kind and the extensions each may be stored under
 * (docs/architecture/media.md, "Accepted files"; I-168). Anything else is
 * refused.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

final class FileTypes
{
    /**
     * Detected type => kind, and the extensions it may be stored under. The
     * first is used unless the uploaded name ends in another from the same
     * list: fileinfo cannot tell a CSV from plain text, or the old Office
     * formats from each other, so the name may choose — but only among these.
     *
     * @var array<string, array{kind: string, extensions: list<string>}>
     */
    private const array TYPES = [
        'image/jpeg' => ['kind' => 'image', 'extensions' => ['jpg', 'jpeg']],
        'image/png' => ['kind' => 'image', 'extensions' => ['png']],
        'image/gif' => ['kind' => 'image', 'extensions' => ['gif']],
        'image/webp' => ['kind' => 'image', 'extensions' => ['webp']],
        'image/avif' => ['kind' => 'image', 'extensions' => ['avif']],
        'image/bmp' => ['kind' => 'image', 'extensions' => ['bmp']],
        'image/x-ms-bmp' => ['kind' => 'image', 'extensions' => ['bmp']],
        'image/heic' => ['kind' => 'image', 'extensions' => ['heic']],
        'image/heif' => ['kind' => 'image', 'extensions' => ['heif']],
        'video/mp4' => ['kind' => 'video', 'extensions' => ['mp4', 'm4v']],
        'video/webm' => ['kind' => 'video', 'extensions' => ['webm']],
        'video/quicktime' => ['kind' => 'video', 'extensions' => ['mov']],
        'audio/mpeg' => ['kind' => 'audio', 'extensions' => ['mp3']],
        'audio/mp4' => ['kind' => 'audio', 'extensions' => ['m4a']],
        'audio/x-m4a' => ['kind' => 'audio', 'extensions' => ['m4a']],
        'audio/ogg' => ['kind' => 'audio', 'extensions' => ['ogg', 'oga']],
        'audio/wav' => ['kind' => 'audio', 'extensions' => ['wav']],
        'audio/x-wav' => ['kind' => 'audio', 'extensions' => ['wav']],
        'application/pdf' => ['kind' => 'document', 'extensions' => ['pdf']],
        'application/msword' => ['kind' => 'document', 'extensions' => ['doc']],
        'application/vnd.ms-excel' => ['kind' => 'document', 'extensions' => ['xls']],
        'application/vnd.ms-powerpoint' => ['kind' => 'document', 'extensions' => ['ppt']],
        'application/vnd.ms-office' => ['kind' => 'document', 'extensions' => ['doc', 'xls', 'ppt']],
        'application/CDFV2' => ['kind' => 'document', 'extensions' => ['doc', 'xls', 'ppt']],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['kind' => 'document', 'extensions' => ['docx']],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['kind' => 'document', 'extensions' => ['xlsx']],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['kind' => 'document', 'extensions' => ['pptx']],
        'application/vnd.oasis.opendocument.text' => ['kind' => 'document', 'extensions' => ['odt']],
        'application/vnd.oasis.opendocument.spreadsheet' => ['kind' => 'document', 'extensions' => ['ods']],
        'application/vnd.oasis.opendocument.presentation' => ['kind' => 'document', 'extensions' => ['odp']],
        'text/plain' => ['kind' => 'document', 'extensions' => ['txt', 'csv']],
        'text/csv' => ['kind' => 'document', 'extensions' => ['csv']],
        'application/csv' => ['kind' => 'document', 'extensions' => ['csv']],
        'text/vtt' => ['kind' => 'document', 'extensions' => ['vtt']],
    ];

    /**
     * The kind and stored extension for a file of this detected type,
     * uploaded under this name — or null if the type is not accepted.
     *
     * @return array{kind: string, extension: string}|null
     */
    public static function for(string $mimeType, string $originalFilename): ?array
    {
        $type = self::TYPES[$mimeType] ?? null;

        if ($type === null) {
            return null;
        }

        $named = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        return [
            'kind' => $type['kind'],
            'extension' => in_array($named, $type['extensions'], true) ? $named : $type['extensions'][0],
        ];
    }
}
