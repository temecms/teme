<?php

/**
 * Whether a plugin may write terms as the site: only an enabled plugin
 * granted terms.write.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginTermGrant
{
    /**
     * Refuses unless the plugin with this code is enabled and was granted terms.write.
     *
     * @throws AuthorizationException
     */
    public static function require(string $code): void
    {
        $record = PluginRecord::query()->where('code', $code)->first();

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || ! in_array(Capability::TermsWrite->value, $record->consent['capabilities'] ?? [], true)) {
            throw new AuthorizationException(__('This plugin may not change terms.'));
        }
    }
}
