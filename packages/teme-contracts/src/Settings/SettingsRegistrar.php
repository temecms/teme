<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * Where settings groups are declared. Internal in phase 1; the extension
 * contract in phase 5, unchanged in shape.
 *
 * @since 0.1.0
 */
interface SettingsRegistrar
{
    /** @since 0.1.0 */
    public function group(SettingsGroup $group): void;
}
