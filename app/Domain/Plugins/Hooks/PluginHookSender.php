<?php

/**
 * A plugin's door to send the hooks it declares
 * (docs/plans/plugin-sockets-3.md): it refuses any other class, then hands
 * the hook to core's dispatcher unchanged, so listeners' faults, failure
 * rules, order and depth are those of core's hooks. A notification waits
 * for the outermost commit (rule 6).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Hooks;

use App\Domain\Hooks\HookDispatcher;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Transform;

final readonly class PluginHookSender implements HookSender
{
    /**
     * @param  string  $label  the plugin's name as people read it
     * @param  list<string>  $classes  the classes it declares under contributes.hooks
     */
    public function __construct(
        private string $label,
        private array $classes,
        private HookDispatcher $dispatcher,
    ) {}

    public function notify(Notification $hook): void
    {
        $this->check($hook);

        DB::afterCommit(fn () => $this->dispatcher->notify($hook));
    }

    public function transform(Transform $hook): mixed
    {
        $this->check($hook);

        return $this->dispatcher->transform($hook);
    }

    public function collect(Collect $hook): array
    {
        $this->check($hook);

        return $this->dispatcher->collect($hook);
    }

    /**
     * @throws HookRefused for a class the plugin doesn't declare
     */
    private function check(Hook $hook): void
    {
        $declared = array_map(fn (string $class): string => strtolower(ltrim($class, '\\')), $this->classes);

        if (! in_array(strtolower($hook::class), $declared, true)) {
            throw new HookRefused(__(':plugin sends :hook, which its teme-plugin.json doesn’t declare under contributes.hooks.', [
                'plugin' => $this->label,
                'hook' => HookDispatcher::shortName($hook::class),
            ]));
        }
    }
}
