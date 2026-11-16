<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One activity on its way to one inbox: queued, sent, failed or skipped,
 * with how often it was tried and what the inbox last answered.
 *
 * @property string $id
 * @property string $activity_id
 * @property string $inbox_url
 * @property string $host
 * @property string $state
 * @property int $attempts
 * @property CarbonImmutable $next_attempt_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 */
final class Delivery extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'fediverse_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
