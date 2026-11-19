<?php

/**
 * Where a plugin stands (I-3353): enabled or not, by whom, and why it isn't
 * running when it should be. Each has the badge the Plugins page shows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

enum PluginState: string
{
    case NotEnabled = 'not_enabled';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case DisabledOnServer = 'disabled_on_server';
    case StoppedByTeme = 'stopped_by_teme';
    case Waiting = 'waiting';
    case CantBeUsed = 'cant_be_used';
    case CodeGone = 'code_gone';
    case SomePartsStopped = 'some_parts_stopped';

    /**
     * The badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotEnabled, self::Disabled => 'Disabled',
            self::Enabled => 'Enabled',
            self::DisabledOnServer => 'Disabled on the server',
            self::StoppedByTeme => 'Stopped by Teme',
            self::Waiting => 'Needs attention',
            self::CantBeUsed, self::CodeGone => 'Can’t be used',
            self::SomePartsStopped => 'Some parts stopped',
        };
    }
}
