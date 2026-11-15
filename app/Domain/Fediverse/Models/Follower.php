<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remote account following one of the site's actors, and the Follow
 * that started it. Keyed by the pair, so it is written and removed by
 * query, never saved through the model.
 *
 * @property string $local_actor_id
 * @property string $remote_actor_id
 * @property string|null $follow_uri
 * @property CarbonImmutable $since
 */
final class Follower extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'fediverse_followers';

    protected $primaryKey = 'remote_actor_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['since' => 'immutable_datetime'];
    }

    /**
     * The remote account that follows.
     *
     * @return BelongsTo<RemoteActor, $this>
     */
    public function remoteActor(): BelongsTo
    {
        return $this->belongsTo(RemoteActor::class);
    }
}
