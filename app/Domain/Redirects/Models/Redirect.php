<?php

/**
 * One redirect: where it starts, what it answers, and where it sends readers
 * (I-297). Only the redirect Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source
 * @property string $kind
 * @property string|null $pattern
 * @property int $status
 * @property string|null $target_kind
 * @property string|null $target
 * @property string|null $target_path
 * @property string $origin
 * @property string|null $plugin
 * @property bool $enabled
 * @property string $note
 * @property int $hits
 * @property string|null $last_hit_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
final class Redirect extends Model
{
    use HasUlids;

    protected $table = 'redirects';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'enabled' => 'boolean',
            'hits' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
