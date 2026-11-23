<?php

/**
 * A WordPress export file uploaded to import from: its upload's progress,
 * whether it has been read, and the site it belongs to once read. Written
 * only by the import Operations.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $source_id
 * @property int|null $uploaded_by
 * @property string $name
 * @property int $size_bytes
 * @property int $received_bytes
 * @property string|null $sha256
 * @property string $state
 * @property string|null $refused
 * @property int $read_items
 * @property string|null $site_address
 * @property string|null $wxr_version
 * @property CarbonImmutable|null $made_at
 * @property array<string, mixed>|null $report
 * @property CarbonImmutable|null $deleted_at
 */
final class ImportFile extends Model
{
    use HasUlids;

    /**
     * Where the file's bytes are kept on the private local disk, by its ID.
     */
    public const string FOLDER = 'imports';

    protected $table = 'import_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'uploaded_by' => 'integer',
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'read_items' => 'integer',
            'report' => 'array',
            'made_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * The file's path on the private local disk.
     */
    public function path(): string
    {
        return self::FOLDER.'/'.$this->id.'.xml';
    }

    /**
     * The site the file was read into, or none before it has been read.
     *
     * @return BelongsTo<ImportSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ImportSource::class, 'source_id');
    }
}
