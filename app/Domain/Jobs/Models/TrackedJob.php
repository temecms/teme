<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Models;

use App\Domain\Jobs\JobState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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
}
