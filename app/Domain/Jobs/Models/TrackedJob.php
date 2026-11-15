<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use App\Domain\Jobs\JobState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * One piece of background work as people follow it: what it is, who started
 * it, how far it's got and how it ended (I-104). Only the job Operations
 * write it.
 *
 * @property string $id
 * @property string $kind
 * @property string $owner
 * @property string $label
 * @property string $starter
 * @property int|null $started_by
 * @property string|null $run_type
 * @property string|null $run_id
 * @property JobState $state
 * @property int|null $total
 * @property int $done
 * @property string|null $message
 * @property array<string, mixed> $payload
 */
final class TrackedJob extends Model
{
    use HasUlids;

    protected $table = 'tracked_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => JobState::class,
            'total' => 'integer',
            'done' => 'integer',
            'started_by' => 'integer',
        ];
    }

    /**
     * Not an `array` cast: an empty PHP array encodes as a JSON list, and
     * the column is constrained to an object (I-3445).
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null || $value === '' ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }
}
