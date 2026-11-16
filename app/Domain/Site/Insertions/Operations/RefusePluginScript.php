<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Site\Insertions\Operations\Concerns\DecidesPluginScripts;
use App\Domain\Site\Insertions\Operations\Input\RefusePluginScriptInput;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Refuses a waiting script a plugin asked to keep: it stays refused, and
 * the plugin asking again changes nothing, until someone revokes the
 * decision (docs/plans/plugin-sockets-2.md, B4).
 */
final class RefusePluginScript extends BaseOperation implements RequiresElevation
{
    use DecidesPluginScripts;

    protected function validate(OperationInput $input): void
    {
        // The script is found under its lock, in perform().
    }

    /**
     * @return array{name: string, plugin: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RefusePluginScriptInput $input */
        $script = $this->lockedScript($input->scriptId);

        if ($script->state !== PluginScript::WAITING) {
            throw ValidationException::withMessages(['script' => __('Only a script that waits for approval can be refused.')]);
        }

        $before = $script->snapshot();
        $script->state = PluginScript::REFUSED;
        $script->decided_by = (int) $input->actorId;
        $script->decided_at = CarbonImmutable::now();
        $script->save();

        $this->decided(SecurityEventType::PluginScriptRefused, $input, $script->id, $before, $script->snapshot());

        return ['name' => $script->name, 'plugin' => $script->plugin_code];
    }
}
