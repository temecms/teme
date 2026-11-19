<?php

/**
 * One reader's comment on one content item, placed in its thread by `path`
 * (I-272). Only the comment Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Models;

use App\Domain\Comments\CommentKind;
use App\Domain\Contents\Models\Content;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $content_id
 * @property string|null $parent_id
 * @property string $path
 * @property int $depth
 * @property CommentKind $kind
 * @property string $status
 * @property string|null $previous_status
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string|null $author_url
 * @property int|null $author_user_id
 * @property array<string, mixed>|null $body
 * @property string|null $commenter_token_hash
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $legacy_id
 * @property array<string, mixed>|null $moderation
 * @property string $status_changed_at when its status last changed, to the microsecond; no cast, which would drop them (1.8c′'s D2)
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class Comment extends Model
{
    use HasUlids;

    protected $table = 'comments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'moderation' => 'array',
            'kind' => CommentKind::class,
            'depth' => 'integer',
            'legacy_id' => 'integer',
            'author_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Content, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id');
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
