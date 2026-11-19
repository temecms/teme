<?php

/**
 * A place backups are kept, each its own restic repository (I-360): its
 * kind and location, its credentials encrypted and hidden, restic's ID for
 * its repository, and whether it is answering. A restore pauses it until a
 * person says whether this server is now the site. Only the backup Operations
 * write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Models;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use stdClass;

/**
 * @property string $id
 * @property string $name
 * @property BackupDestinationKind $kind
 * @property array<string, string|int> $location
 * @property array<string, string>|null $credentials
 * @property bool $off_server
 * @property string $repository_id
 * @property BackupDestinationState $state
 * @property CarbonImmutable|null $last_answered_at
 * @property string|null $last_error
 * @property int|null $added_by
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BackupDestination extends Model
{
    use HasUlids;

    protected $table = 'backup_destinations';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'kind' => BackupDestinationKind::class,
            'state' => BackupDestinationState::class,
            // JSON inside encryptString(), never serialize() (rule 4).
            'credentials' => 'encrypted:array',
            'off_server' => 'boolean',
            'added_by' => 'integer',
            'removed_by' => 'integer',
            'last_answered_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not an `array` cast: the column holds an object, and an empty PHP
     * array would encode as a list.
     *
     * @return Attribute<array<string, string|int>, array<string, string|int>>
     */
    protected function location(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): array => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR),
        );
    }

    /** @return HasMany<BackupKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(BackupKey::class, 'destination_id');
    }

    /** @return HasMany<BackupRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'destination_id');
    }
}
