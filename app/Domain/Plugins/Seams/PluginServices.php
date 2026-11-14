<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Teme hands a plugin's constructor, by interface: the only way a
 * plugin is given anything, never the container (I-3353): PluginDoors
 * answers.
 */
interface PluginServices
{
    /** The contracts a plugin's constructor may ask for, in the first release. */
    public const array OFFERED = [SettingsReader::class, SecretReader::class, OutboundHttp::class];

    /**
     * What this plugin's constructor may be given, by interface.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     *
     * @return array<class-string, object>
     */
    public function for(string $name, string $code, array $capabilities, array $hosts): array;
}
