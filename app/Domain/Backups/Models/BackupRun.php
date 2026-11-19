<?php

/**
 * One thing Teme did to its backups (I-362): a backup, a copy, a check, a read-back, a test restore, a prune or a restore, when it ran, what started it, how it ended, and restic's snapshot and figures. Never a key or a secret, in its message too. Only the backup Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Models;

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * @property string $id
 * @property BackupRunKind $kind
 * @property BackupRunState $state
 * @property BackupTrigger $started_by
 * @property int|null $actor_id
 * @property string|null $destination_id
 * @property string|null $parent_id
 * @property string|null $snapshot_id
 * @property array<string, mixed> $figures
 * @property string|null $message
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
final class BackupRun extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'backup_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => BackupRunKind::class,
            'state' => BackupRunState::class,
            'started_by' => BackupTrigger::class,
            'actor_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not an `array` cast: the column holds an object, and an empty PHP
     * array would encode as a list.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function figures(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): array => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }

    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }

    /** @return BelongsTo<BackupRun, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
