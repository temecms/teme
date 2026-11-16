<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Site\Insertions\Operations\Input\RequestPluginScriptInput;
use App\Domain\Site\Insertions\Scripts\ApprovedScripts;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Plugins\Capability;

/**
 * Asks, for a person, that one of an enabled plugin's inline scripts be
 * approved by its exact text (docs/plans/plugin-sockets-2.md, B4): a new
 * text waits; one already waiting, approved or refused changes nothing. At
 * most MOST_WAITING wait for a plugin at once. Asking never approves.
 */
final class RequestPluginScript extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RequestPluginScriptInput $input */
        if (! ctype_digit($input->actorId) || User::query()->find($input->actorId) === null) {
            throw new AuthorizationException('A plugin asks for a script on behalf of a person.');
        }

        $record = PluginRecord::query()->where('code', $input->pluginCode)->where('state', PluginRecord::ENABLED)->first();
        $capabilities = $record?->consent['capabilities'] ?? [];

        if (! in_array(Capability::ContentInsert->value, $capabilities, true) && ! in_array(Capability::PagesInsert->value, $capabilities, true)) {
            throw new AuthorizationException('Only an enabled plugin that inserts content may ask for a script.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RequestPluginScriptInput $input */
        $errors = [];
        $name = trim($input->name);

        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = __('A script’s name is 1 to 80 characters.');
        }

        if (trim($input->script) === '') {
            $errors['script'] = __('The script is empty.');
        } elseif (mb_strlen($input->script) > PluginScript::LONGEST) {
            $errors['script'] = __('A script is at most :count characters.', ['count' => number_format(PluginScript::LONGEST)]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return 'waiting'|'approved'|'refused' what the script's text now is for the plugin
     */
    protected function perform(OperationInput $input): string
    {
        /** @var RequestPluginScriptInput $input */
        // The plugin's row, locked, so two requests can't both pass the count.
        PluginRecord::query()->where('code', $input->pluginCode)->lockForUpdate()->first();

        $hash = ApprovedScripts::hash($input->script);
        $known = PluginScript::query()->where('plugin_code', $input->pluginCode)->where('hash', $hash)->first();

        if ($known !== null) {
            /** @var 'waiting'|'approved'|'refused' */
            return $known->state;
        }

        if (PluginScript::query()->where('plugin_code', $input->pluginCode)->where('state', PluginScript::WAITING)->count() >= PluginScript::MOST_WAITING) {
            throw ValidationException::withMessages(['script' => __('Too many scripts wait for approval. Ask an administrator to look at Settings › Site code.')]);
        }

        PluginScript::query()->create([
            'plugin_code' => $input->pluginCode,
            'name' => trim($input->name),
            'hash' => $hash,
            'script' => str_replace(["\r\n", "\r"], "\n", $input->script),
            'state' => PluginScript::WAITING,
            'requested_by' => (int) $input->actorId,
            'requested_at' => CarbonImmutable::now(),
        ]);

        return PluginScript::WAITING;
    }
}
