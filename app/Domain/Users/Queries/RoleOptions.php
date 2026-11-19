<?php

/**
 * The core roles as choices for forms.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Users\Roles;

final class RoleOptions
{
    /**
     * The core roles, most powerful first, as value and label pairs.
     *
     * @return list<array{value: string, label: string}>
     */
    public function all(): array
    {
        $options = [];

        foreach (Roles::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}
