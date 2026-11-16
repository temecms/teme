<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * Where settings groups are declared.
 *
 * @since 0.1.0
 */
interface SettingsRegistrar
{
    /** @since 0.1.0 */
    public function group(SettingsGroup $group): void;
}
