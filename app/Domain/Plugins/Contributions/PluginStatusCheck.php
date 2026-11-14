<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Plugins\PluginAccess;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Teme\Contracts\Status\StatusCheck as PluginCheck;
use Throwable;

/**
 * One plugin's status check, asked as Teme asks its own: shown to holders
 * of plugins.manage, always below every banner of Teme's own, its text cut
 * to 300 characters, and reported under its plugin, showing nothing, when
 * it throws (I-3353).
 */
final readonly class PluginStatusCheck implements StatusCheck
{
    /** Each level's priority: below the lowest core banner's, 5. */
    private const array PRIORITIES = ['error' => 3, 'warning' => 2, 'info' => 1];

    /** The longest banner text a plugin may show, its last character an ellipsis when cut. */
    private const int MOST_CHARACTERS = 300;

    public function __construct(
        private string $owner,
        private string $code,
        private string $label,
        private PluginCheck $check,
        private StatusFaults $faults,
    ) {}

    public function ability(): string
    {
        return PluginAccess::MANAGE;
    }

    public function banner(): ?StatusBanner
    {
        try {
            $notice = $this->check->notice();
        } catch (Throwable $failure) {
            $this->faults->report($this->owner, "{$this->owner}#status", $failure);

            return null;
        }

        if ($notice === null) {
            return null;
        }

        $level = array_key_exists($notice->level, self::PRIORITIES) ? $notice->level : 'info';

        return new StatusBanner(
            $level,
            mb_strlen($notice->message) > self::MOST_CHARACTERS ? mb_substr($notice->message, 0, self::MOST_CHARACTERS - 1).'…' : $notice->message,
            $notice->opensSettings ? __('Open :label’s settings', ['label' => $this->label]) : null,
            $notice->opensSettings ? route('settings.show', ['group' => "plugin.{$this->code}"]) : null,
            self::PRIORITIES[$level],
        );
    }
}
