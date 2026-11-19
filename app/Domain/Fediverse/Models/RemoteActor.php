<?php

/**
 * A fediverse account on another server that Teme has heard from: its
 * address, where to deliver to it, its public key and the document it was
 * read from, refreshed when a signature no longer matches.
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
 * @property string $uri
 * @property string|null $handle
 * @property string $host
 * @property string|null $name
 * @property string|null $profile_url
 * @property string|null $avatar_url
 * @property string $inbox_url
 * @property string|null $shared_inbox_url
 * @property string $key_id
 * @property string $public_key_pem
 * @property array<string, mixed> $document
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $failing_since
 * @property CarbonImmutable|null $gone_at
 */
final class RemoteActor extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'fediverse_remote_actors';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'fetched_at' => 'immutable_datetime',
            'failing_since' => 'immutable_datetime',
            'gone_at' => 'immutable_datetime',
        ];
    }
}
