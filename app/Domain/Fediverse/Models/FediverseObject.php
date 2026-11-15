<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An object the fediverse knows by its address: one of the site's items as
 * a post, or a reply that arrived as a comment, with the remote account
 * that wrote it. Its address is stored verbatim and answers for ever.
 *
 * @property string $uri
 * @property string $kind post or reply
 * @property string|null $content_id
 * @property string|null $comment_id
 * @property string|null $remote_actor_id
 * @property CarbonImmutable|null $deleted_at
 */
final class FediverseObject extends Model
{
    public const string POST = 'post';

    public const string REPLY = 'reply';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'fediverse_objects';

    protected $primaryKey = 'uri';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }
}
