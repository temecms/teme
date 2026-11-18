<?php

/**
 * One person's unsaved work in one article: the whole draft as they left it,
 * and the revision it was based on. It is not a revision; a checkpoint turns
 * it into one. Only the editor's Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $document
 * @property array<string, mixed> $fields
 * @property list<string> $terms
 * @property Carbon $saved_at
 * @property array{editor: string, number: int}|null $save_sequence
 */
final class ContentAutosave extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'content_autosaves';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'fields' => 'array',
            'terms' => 'array',
            'word_count' => 'integer',
            'saved_at' => 'datetime',
            'save_sequence' => 'array',
        ];
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
