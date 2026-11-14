<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\Concerns\JsonObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per source object: what it became in Teme, its state, the last
 * run's outcome and the hash of the source's item. Written only by the
 * import Operations (I-332).
 *
 * @property string $id
 * @property string $source_id
 * @property string $object_type
 * @property string $object_id
 * @property string|null $teme_type
 * @property string|null $teme_id
 * @property ImportStatus $status
 * @property ImportOutcome $outcome
 * @property string|null $source_hash
 * @property array<string, mixed> $facts
 * @property string|null $run_id
 * @property CarbonImmutable $imported_at
 */
final class ImportMapEntry extends Model
{
    use HasUlids;
    use JsonObject;

    public $timestamps = false;

    protected $table = 'import_map';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'outcome' => ImportOutcome::class,
            'imported_at' => 'immutable_datetime',
        ];
    }

    /**
     * What a phase keeps for the object that Teme has no column for.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function facts(): Attribute
    {
        return self::jsonObject();
    }
}
