<?php

/**
 * One menu an editor builds under Content › Navigation: a name, a handle
 * made from it once, the language it is written in (null for the site's
 * default, I-3360), and a tree of items. Only the menu Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $handle
 * @property string|null $language
 */
final class Navigation extends Model
{
    use HasUlids;

    protected $table = 'navigations';

    protected $guarded = [];

    /**
     * @return HasMany<NavigationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(NavigationItem::class);
    }
}
