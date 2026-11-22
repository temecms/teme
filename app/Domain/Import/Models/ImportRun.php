<?php

/**
 * One import of a source: its kind and state, the phase it is in and that
 * attempt's token, its counts and why it stopped. Written only by the
 * import Operations (I-332).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\Concerns\JsonObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $source_id
 * @property string|null $file_id
 * @property ImportRunKind $kind
 * @property ImportRunState $state
 * @property string|null $phase
 * @property string|null $phase_token
 * @property array<string, mixed> $options
 * @property array<string, mixed> $choices
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property int $warning_count
 * @property CarbonImmutable $high_water_at
 * @property string|null $stop_reason
 * @property string|null $stop_message
 * @property int|null $started_by
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
final class ImportRun extends Model
{
    use HasUlids;
    use JsonObject;

    protected $table = 'import_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ImportRunKind::class,
            'state' => ImportRunState::class,
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'warning_count' => 'integer',
            'started_by' => 'integer',
            'high_water_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * The options the run was started with.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function options(): Attribute
    {
        return self::jsonObject();
    }

    /**
     * The choices the run was started with.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function choices(): Attribute
    {
        return self::jsonObject();
    }

    /**
     * The site this run imports.
     *
     * @return BelongsTo<ImportSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ImportSource::class, 'source_id');
    }
}
