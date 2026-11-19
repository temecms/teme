<?php

/**
 * One category or tag. Categories may have a parent; published_count is the
 * number of live, published items assigned it, and working_count the number of
 * live items of any status, both maintained by TermProjection. Only the
 * taxonomy Operations write them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $taxonomy
 * @property string $name
 * @property string $slug
 * @property string|null $parent_id
 * @property string|null $description
 * @property array<string, mixed> $fields
 * @property int $published_count
 * @property int $working_count
 * @property string|null $language the stored language: null for the site's default (I-3360)
 * @property string|null $translation_group
 */
final class Term extends Model
{
    use HasUlids;

    protected $table = 'terms';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'published_count' => 'integer',
            'working_count' => 'integer',
        ];
    }
}
