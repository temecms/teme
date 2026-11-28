<?php

/**
 * The copy of an old site's uploads folder kept for its import: a zip or a
 * folder, read in place or from the private disk, belonging to one site's
 * record. Written only by the import Operations.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $source_id
 * @property string $kind
 * @property string $path
 * @property string $name
 * @property int $size_bytes
 * @property int|null $attached_by
 */
final class ImportUpload extends Model
{
    use HasUlids;

    protected $table = 'import_uploads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'attached_by' => 'integer',
        ];
    }

    /**
     * The site this copy belongs to.
     *
     * @return BelongsTo<ImportSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ImportSource::class, 'source_id');
    }
}
