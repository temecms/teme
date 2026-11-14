<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One result in a person's inbox: what it says, where it leads, and whether
 * they've read it (I-221). Only the inbox Operations write it.
 *
 * @property string $id
 * @property int $user_id
 * @property string $kind
 * @property string $owner
 * @property string|null $read_at
 * @property array{title: string, text: string|null, link: array{route: string, parameters: array<string, string|int>, ability: string|null}|null} $body
 */
final class InboxItem extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'inbox_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'user_id' => 'integer',
        ];
    }
}
