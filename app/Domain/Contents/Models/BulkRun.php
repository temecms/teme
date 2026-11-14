<?php

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * One Select all matching (I-143): the list it walks, its action, where it
 * stopped and what it skipped. Only StartBulkRun and RecordBulkRunStep write it.
 *
 * @property string $id
 * @property string $tracked_job_id
 * @property int $user_id
 * @property string $content_type
 * @property string $action
 * @property array<string, string> $filters
 * @property string|null $after
 * @property int $done
 * @property array<string, int> $skipped
 * @property string|null $refusal
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BulkRun extends Model
{
    use HasUlids;

    protected $table = 'bulk_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'done' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not an `array` cast: an empty PHP array encodes as a JSON list, and the
     * column is constrained to an object, so an empty set is written as `{}`.
     *
     * @return Attribute<array<string, string>, array<string, string>>
     */
    protected function filters(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): array => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return Attribute<array<string, int>, array<string, int>>
     */
    protected function skipped(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): array => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
