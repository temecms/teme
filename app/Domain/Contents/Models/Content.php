<?php

/**
 * One content item — a post or a page — pointing at its published and working
 * revisions. Only the content Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use App\Domain\Comments\Discussion;
use App\Domain\Contents\Visibility;
use App\Models\User;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string, mixed> $fields
 * @property Discussion $comment_status
 * @property Visibility $visibility
 * @property string|null $reader_password
 * @property string|null $parent_id
 * @property int $position
 * @property string|null $language the stored language: null for the site's default (I-3360)
 * @property string|null $translation_group
 */
final class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected $table = 'contents';

    protected $guarded = [];

    protected $attributes = ['visibility' => 'public'];

    protected $hidden = ['reader_password'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'comment_status' => Discussion::class,
            'visibility' => Visibility::class,
            'published_at' => 'datetime',
            'scheduled_for' => 'datetime',
        ];
    }

    protected static function newFactory(): ContentFactory
    {
        return ContentFactory::new();
    }

    /** @return HasMany<ContentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class);
    }

    /** @return BelongsTo<ContentRevision, $this> */
    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(ContentRevision::class, 'published_revision_id');
    }

    /** @return BelongsTo<ContentRevision, $this> */
    public function workingRevision(): BelongsTo
    {
        return $this->belongsTo(ContentRevision::class, 'working_revision_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
