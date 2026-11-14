<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * The export's manifest (I-365, I-2408): its format and Teme's version,
 * each NDJSON file with its lines, bytes and SHA-256, the types file's
 * name, bytes and SHA-256, the media folder's count and the originals missing or changed, and what is left out and
 * why. Written last, so a folder holding one is a whole export.
 */
final class ExportManifest
{
    public const string FORMAT = 'teme-export';

    public const int FORMAT_VERSION = 1;

    /** @var array<string, string> */
    public const array FIELDS = [
        'format' => '`teme-export`.',
        'format_version' => '1.',
        'teme_version' => 'The Teme that wrote it, such as `0.1.0`.',
        'made_at' => 'When the export was made.',
        'made_from' => 'The site\'s address when it was made, for the record; nothing in the export depends on it.',
        'files' => 'Each NDJSON file, in the order written: its `kind`, `file`, `version`, `lines`, `bytes` and `sha256`.',
        'types_file' => 'The site\'s own content types, taxonomies and fields, with the permission grants of its own types, as `teme-types.json` (the file `teme:types:export` prints): its `file`, `bytes` and `sha256`. Import it first, with `teme:types:import`, so the items\' types, taxonomies and fields are defined.',
        'media.folder' => '`media`.',
        'media.files' => 'How many originals were copied.',
        'media.bytes' => 'Their size together.',
        'media.missing' => 'The IDs of media items whose original couldn\'t be read.',
        'media.differs' => 'The IDs of media items whose original has changed since it was uploaded.',
        'left_out' => 'What the export leaves out, and why: a list of `what` and `why`.',
    ];

    /**
     * @param  list<ExportedFile>  $files
     * @param  array{file: string, bytes: int, sha256: string}  $typesFile
     *
     * @return array<string, mixed>
     */
    public static function make(array $files, array $typesFile, MediaTally $media, string $madeAt): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'teme_version' => (string) config('teme.version'),
            'made_at' => $madeAt,
            'made_from' => (string) config('app.url'),
            'files' => array_map(fn (ExportedFile $file): array => $file->toManifest(), $files),
            'types_file' => $typesFile,
            'media' => [
                'folder' => 'media',
                'files' => $media->files,
                'bytes' => $media->bytes,
                'missing' => $media->missing,
                'differs' => $media->differs,
            ],
            'left_out' => LeftOut::ALL,
        ];
    }
}
