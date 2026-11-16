<?php

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a content item's title, document and fields. A revision
 * is never edited: saving writes a new one.
 *
 * @property array<string, mixed> $document
 * @property array<string, mixed> $fields
 * @property list<string> $terms
 * @property string|null $source_revision_id the revision of another version this one was translated from (I-3360)
 * @property string|null $via the plugin code that wrote this revision, or null for a person
 */
final class ContentRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'content_revisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'fields' => 'array',
            'terms' => 'array',
            'word_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The revision a restore was taken from; null for any other kind.
     *
     * @return BelongsTo<ContentRevision, $this>
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }
}
