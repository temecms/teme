<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Scripts;

use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Site\Insertions\Operations\Input\RequestPluginScriptInput;
use App\Domain\Site\Insertions\Operations\RequestPluginScript;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Insertions\InlineScripts;

/**
 * A plugin's door to ask for its inline scripts to be approved, through
 * RequestPluginScript, for the person this work runs for: the signed-in
 * person, or a job's; never the operator. It can only ask.
 */
final readonly class PluginInlineScripts implements InlineScripts
{
    public function __construct(private string $code) {}

    public function request(string $name, string $script): void
    {
        $person = $this->person() ?? throw new WriteRefused(__('A plugin asks for a script on behalf of a person, and none is using it.'));

        try {
            app(RequestPluginScript::class)->execute(new RequestPluginScriptInput($this->code, $name, $script, $person));
        } catch (ValidationException $refused) {
            throw new WriteRefused((string) collect($refused->errors())->flatten()->first());
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage());
        }
    }

    /** The job's person while a job runs, else the signed-in person; null for the operator or nobody. */
    private function person(): ?string
    {
        $job = app(CurrentJobActor::class)->get();

        if ($job !== null) {
            return $job->isOperator() ? null : $job->userId;
        }

        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
