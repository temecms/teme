<?php

/**
 * A ZIP archive someone uploaded, kept as data until the installer unpacks
 * it: the web process reads only its composer.json. Deleted when its
 * request ends.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $file_name
 * @property int $size
 * @property string $sha256
 * @property string $package
 * @property string $version
 * @property mixed $bytes
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 */
final class ExtensionUploadRecord extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'extension_uploads';

    protected $guarded = [];

    protected $hidden = ['bytes'];

    /** Returns the archive's bytes, as PostgreSQL's bytea gives them back. */
    public function archive(): string
    {
        $bytes = $this->bytes;

        return is_resource($bytes) ? (string) stream_get_contents($bytes) : (string) $bytes;
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
