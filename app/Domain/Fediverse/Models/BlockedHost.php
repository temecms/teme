<?php

/**
 * A server the site neither hears from nor delivers to (proposal, Settled
 * 8): refused at the inbox before anything is fetched.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $host
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property int|null $created_by
 */
final class BlockedHost extends Model
{
    public const ?string UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'fediverse_blocked_hosts';

    protected $primaryKey = 'host';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'created_by' => 'integer',
        ];
    }
}
