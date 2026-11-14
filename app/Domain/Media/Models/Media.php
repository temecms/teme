<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use stdClass;

/**
 * One uploaded file: an image, a video, an audio file or a document, with the
 * paths of its original and its variants. Only the media Operations write it.
 *
 * @property string $id
 * @property string $kind
 * @property string $mime_type
 * @property string $extension
 * @property string $original_filename
 * @property string $name
 * @property string $original_path
 * @property int $file_version
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $width
 * @property int|null $height
 * @property bool $animated
 * @property string $title
 * @property string|null $alt
 * @property string|null $caption
 * @property string|null $credit
 * @property string|null $licence
 * @property int $focal_x
 * @property int $focal_y
 * @property array<string, array{path: string, width: int, height: int, bytes: int}> $variants
 * @property int $variant_set
 * @property string $processing
 * @property string|null $processing_error
 * @property int|null $uploaded_by
 * @property string|null $source_url
 * @property string|null $uploaded_for
 * @property int $working_uses
 * @property int $published_uses
 */
final class Media extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'media';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_version' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'animated' => 'boolean',
            'focal_x' => 'integer',
            'focal_y' => 'integer',
            'variant_set' => 'integer',
            'uploaded_by' => 'integer',
            'working_uses' => 'integer',
            'published_uses' => 'integer',
        ];
    }

    /**
     * An empty map is stored as {}, never [], so the CHECK constraint holds
     * (backlog B-033).
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function variants(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true),
            set: fn (array $value): string => (string) json_encode($value === [] ? new stdClass : $value),
        );
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }
}
