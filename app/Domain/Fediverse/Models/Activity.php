<?php

/**
 * An activity one of the site's actors sent, kept as it was sent: the
 * outbox shows the latest, and each delivery points at one.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $local_actor_id
 * @property string $type
 * @property string $object_uri
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
final class Activity extends Model
{
    use HasUlids;

    public const ?string UPDATED_AT = null;

    protected $table = 'fediverse_activities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
