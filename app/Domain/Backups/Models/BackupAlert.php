<?php

/**
 * One alert email about the backups Teme decided to send (I-2409): its kind, the problems it told, and when. Only RaiseBackupAlert writes it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Models;

use App\Domain\Backups\BackupAlertKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property BackupAlertKind $kind
 * @property list<string> $problems
 * @property CarbonImmutable $raised_at
 */
final class BackupAlert extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'backup_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => BackupAlertKind::class,
            'problems' => 'array',
            'raised_at' => 'immutable_datetime',
        ];
    }
}
