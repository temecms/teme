<?php

/**
 * Every media item not in the trash, with what people wrote about it, and its
 * original copied beside the export as each line is made. Never the sizes made
 * from it, nor this server's own path to the file.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use App\Domain\Media\MediaDisks;
use Illuminate\Support\Facades\DB;

final readonly class MediaKind implements ExportKind
{
    public function name(): string
    {
        return 'media';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every media item not in the trash, by ID, each with its original copied into the export’s `media/` folder.';
    }

    public function noun(): string
    {
        return 'media item|media items';
    }

    public function fields(): array
    {
        return [
            'id' => 'The media item\'s ID.',
            'media_kind' => '`image`, `video`, `audio` or `document`.',
            'mime_type' => 'Its type, such as `image/jpeg`.',
            'extension' => 'Its file extension, such as `jpg`.',
            'original_filename' => 'The file\'s name when it was uploaded.',
            'name' => 'Its name in the address of its files.',
            'title' => 'Its title.',
            'alt' => 'Its alternative text, or null.',
            'caption' => 'Its caption, or null.',
            'credit' => 'Who made it, or null.',
            'licence' => 'The licence it is used under, or null.',
            'width' => 'Its width in pixels, or null.',
            'height' => 'Its height in pixels, or null.',
            'animated' => 'Whether it is an animated image.',
            'focal_x' => 'Where its focal point lies across, as a percentage.',
            'focal_y' => 'Where its focal point lies down, as a percentage.',
            'size_bytes' => 'The original\'s size when it was uploaded.',
            'sha256' => 'The original\'s SHA-256 when it was uploaded.',
            'uploaded_by' => 'Who uploaded it, or null.',
            'created_at' => 'When it was uploaded.',
            'updated_at' => 'When its record last changed.',
            'file' => 'The original in this export, or null when it couldn\'t be read (its ID is then in the manifest\'s `media.missing`).',
            'file.path' => 'Its path inside the export, such as `media/01JB2ZQ8V3K7N4W5X6Y7Z8A9BC.jpg`.',
            'file.bytes' => 'Its size, as copied.',
            'file.sha256' => 'Its SHA-256, as copied. When it differs from `sha256`, the file has changed since it was uploaded, and its ID is in the manifest\'s `media.differs`.',
        ];
    }

    private const int PAGE = 1000;

    private const string PAGE_SQL = <<<'SQL'
        SELECT id, kind, mime_type, extension, original_filename, name, title, alt, caption, credit, licence,
               width, height, animated, focal_x, focal_y, size_bytes, sha256, uploaded_by, created_at, updated_at, original_path
        FROM media
        WHERE deleted_at IS NULL AND id > ?
        ORDER BY id
        LIMIT ?
        SQL;

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            foreach ($rows as $row) {
                $id = (string) $row->id;
                $copy = $context->folder->copyOriginal($id, (string) $row->extension, MediaDisks::forOriginal((string) $row->kind), (string) $row->original_path);

                if ($copy === null) {
                    $context->media->missing($id);
                } else {
                    $context->media->copied($copy);

                    if (! hash_equals((string) $row->sha256, $copy->sha256)) {
                        $context->media->differs($id);
                    }
                }

                yield [
                    'id' => $id,
                    'media_kind' => (string) $row->kind,
                    'mime_type' => (string) $row->mime_type,
                    'extension' => (string) $row->extension,
                    'original_filename' => (string) $row->original_filename,
                    'name' => (string) $row->name,
                    'title' => (string) $row->title,
                    'alt' => $row->alt === null ? null : (string) $row->alt,
                    'caption' => $row->caption === null ? null : (string) $row->caption,
                    'credit' => $row->credit === null ? null : (string) $row->credit,
                    'licence' => $row->licence === null ? null : (string) $row->licence,
                    'width' => $row->width === null ? null : (int) $row->width,
                    'height' => $row->height === null ? null : (int) $row->height,
                    'animated' => (bool) $row->animated,
                    'focal_x' => (int) $row->focal_x,
                    'focal_y' => (int) $row->focal_y,
                    'size_bytes' => (int) $row->size_bytes,
                    'sha256' => (string) $row->sha256,
                    'uploaded_by' => $row->uploaded_by === null ? null : (int) $row->uploaded_by,
                    'created_at' => ExportJson::time($row->created_at),
                    'updated_at' => ExportJson::time($row->updated_at),
                    'file' => $copy === null ? null : ['path' => $copy->path, 'bytes' => $copy->bytes, 'sha256' => $copy->sha256],
                ];
            }

            if ($rows !== []) {
                $after = (string) $rows[array_key_last($rows)]->id;
            }
        } while (count($rows) === $page);
    }
}
