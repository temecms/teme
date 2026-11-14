<?php

declare(strict_types=1);

namespace App\Domain\Menus\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One menu an editor builds under Content › Navigation: a name, a handle
 * made from it once, and a tree of items. Only the menu Operations write it.
 *
 * @property string $id
 * @property string $name
 * @property string $handle
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
