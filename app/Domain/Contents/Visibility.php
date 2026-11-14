<?php

declare(strict_types=1);

namespace App\Domain\Contents;

/**
 * Who may read an item once it is published (I-1700): anyone, only whoever
 * may edit it, or whoever gives its password.
 */
enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Protected = 'protected';

    /**
     * The words people read for it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Public => __('Public'),
            self::Private => __('Private'),
            self::Protected => __('Password protected'),
        };
    }
}
