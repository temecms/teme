<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use Teme\Contracts\Settings\SecretReader;

/**
 * The secrets a plugin may read: those of its own settings group,
 * `plugin.{code}`, and nobody else's, not core's and not another plugin's
 * (I-3353).
 */
final readonly class PluginSecrets implements SecretReader
{
    public function __construct(private string $code, private SecretReader $secrets) {}

    public function secret(string $group, string $key): ?string
    {
        return $group === "plugin.{$this->code}" ? $this->secrets->secret($group, $key) : null;
    }
}
