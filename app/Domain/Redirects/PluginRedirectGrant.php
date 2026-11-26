<?php

/**
 * Whether a plugin may write redirects as the site: only an enabled plugin
 * granted redirects.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginRedirectGrant
{
    /**
     * Refuses unless the plugin with this code is enabled and was granted redirects.
     *
     * @throws AuthorizationException
     */
    public static function require(string $code): void
    {
        $record = PluginRecord::query()->where('code', $code)->first();

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || ! in_array(Capability::Redirects->value, $record->consent['capabilities'] ?? [], true)) {
            throw new AuthorizationException(__('This plugin may not change redirects.'));
        }
    }
}
