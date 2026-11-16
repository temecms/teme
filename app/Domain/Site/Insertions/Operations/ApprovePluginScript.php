<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Site\Insertions\Operations\Concerns\DecidesPluginScripts;
use App\Domain\Site\Insertions\Operations\Input\ApprovePluginScriptInput;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Approves a waiting script a plugin asked to keep, by its exact text, so
 * the plugin's inserted markup keeps it and its hash joins the page's
 * policy (docs/plans/plugin-sockets-2.md, B4). Only a person who may change
 * site code, with the password asked again; never a plugin.
 */
final class ApprovePluginScript extends BaseOperation implements RequiresElevation
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
        /** @var ApprovePluginScriptInput $input */
        $script = $this->lockedScript($input->scriptId);

        if ($script->state !== PluginScript::WAITING) {
            throw ValidationException::withMessages(['script' => __('Only a script that waits for approval can be approved.')]);
        }

        $before = $script->snapshot();
        $script->state = PluginScript::APPROVED;
        $script->decided_by = (int) $input->actorId;
        $script->decided_at = CarbonImmutable::now();
        $script->save();

        $this->decided(SecurityEventType::PluginScriptApproved, $input, $script->id, $before, $script->snapshot());

        return ['name' => $script->name, 'plugin' => $script->plugin_code];
    }
}
