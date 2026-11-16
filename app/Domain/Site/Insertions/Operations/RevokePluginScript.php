<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Site\Insertions\Operations\Concerns\DecidesPluginScripts;
use App\Domain\Site\Insertions\Operations\Input\RevokePluginScriptInput;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Revokes a decision on a plugin's script, approved or refused: the row
 * goes, so the script is removed from pages again, and the plugin may ask
 * once more (docs/plans/plugin-sockets-2.md, B4).
 */
final class RevokePluginScript extends BaseOperation implements RequiresElevation
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
        /** @var RevokePluginScriptInput $input */
        $script = $this->lockedScript($input->scriptId);

        if ($script->state === PluginScript::WAITING) {
            throw ValidationException::withMessages(['script' => __('This script waits for a decision; approve or refuse it.')]);
        }

        $before = $script->snapshot();
        $script->delete();

        $this->decided(SecurityEventType::PluginScriptRevoked, $input, $script->id, $before, null);

        return ['name' => $script->name, 'plugin' => $script->plugin_code];
    }
}
