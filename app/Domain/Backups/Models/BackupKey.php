<?php

declare(strict_types=1);

namespace App\Domain\Backups\Models;

use App\Domain\Backups\BackupKeyRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * restic's ID for one key of one place's repository, machine or recovery (I-359), and who added and removed it. Never the key itself.
 *
 * @property string $id
 * @property string $destination_id
 * @property string $restic_key_id
 * @property BackupKeyRole $role
 * @property string $label
 * @property int|null $added_by
 * @property CarbonImmutable $added_at
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 */
final class BackupKey extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'backup_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => BackupKeyRole::class,
            'added_by' => 'integer',
            'removed_by' => 'integer',
            'added_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
