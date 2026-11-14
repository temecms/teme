<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One public address: the current one for its target, or an old one that answers with a 301.
 *
 * @property int $id
 * @property string $path
 * @property string $target_type
 * @property string $target_id
 * @property bool $is_current
 */
final class Path extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'replaced_at' => 'immutable_datetime'];
    }
}
