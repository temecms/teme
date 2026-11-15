<?php

declare(strict_types=1);

namespace App\Domain\Menus\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a menu: the home page, a content item or a term by its ID
 * (rule 9), an outside address as typed, a heading, which opens the
 * items under it, or a language switcher with its options; with its label
 * (a heading's is required) and its place in the tree. Only
 * SaveNavigationItems writes it.
 *
 * @property string $id
 * @property string $navigation_id
 * @property string|null $parent_id
 * @property int $position
 * @property string $kind
 * @property string|null $target_id
 * @property string|null $url
 * @property string|null $label
 * @property bool $opens_new_tab
 * @property array<string, bool>|null $options
 */
final class NavigationItem extends Model
{
    use HasUlids;

    protected $table = 'navigation_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'opens_new_tab' => 'boolean',
            'options' => 'array',
        ];
    }
}
