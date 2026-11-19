<?php

/**
 * Append-only. Secret keys appear in changed_keys, never in before or after.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SettingsChange extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'settings_changes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changed_keys' => 'array',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
