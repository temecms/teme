<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * The only way to read a secret setting. Injected into server code that needs
 * a credential; never bound into view models, composers or themes.
 *
 * @since 0.1.0
 */
interface SecretReader
{
    /** @since 0.1.0 */
    public function secret(string $group, string $key): ?string;
}
