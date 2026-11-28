<?php

/**
 * Whether a plugin may write terms as the site: only an enabled plugin
 * granted terms.write. Queued work from those writes runs as the
 * administrator who enabled the plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginTermGrant
{
    /**
     * Refuses unless the plugin with this code is enabled and was granted terms.write.
     *
     * @throws AuthorizationException
     */
    public static function require(string $code): PluginRecord
    {
        $record = PluginRecord::query()->where('code', $code)->first();

        if ($record === null
            || $record->state !== PluginRecord::ENABLED
            || ! in_array(Capability::TermsWrite->value, $record->consent['capabilities'] ?? [], true)) {
            throw new AuthorizationException(__('This plugin may not change terms.'));
        }

        return $record;
    }

    /**
     * Who queued work from a plugin term write acts as: the administrator
     * who enabled the plugin, as schedules do. Without one, the operator
     * when present; otherwise the write is refused, as a schedule is.
     *
     * @throws AuthorizationException
     */
    public static function jobActor(PluginRecord $record): JobActor
    {
        if ($record->consented_by !== null) {
            return JobActor::person((string) $record->consented_by);
        }

        if (! Operator::isPresent()) {
            throw new AuthorizationException(__('No administrator enabled this plugin, so its term changes that move addresses can’t run. Enable it again from Site › Plugins.'));
        }

        return JobActor::operator();
    }
}
