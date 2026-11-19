<?php

/**
 * teme:extensions:request — asks the installer to install, update or remove a plugin or theme from
 * Packagist, or to roll back to the plugins and themes kept before, as the operator (I-211); the
 * installer carries it out.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class RequestExtensionCommand extends Command
{
    /** The changes an operator may ask for; Teme asks for a rebuild itself. */
    private const array ACTIONS = [ExtensionAction::Install, ExtensionAction::Update, ExtensionAction::Remove, ExtensionAction::Rollback];

    /** @var string */
    protected $signature = 'teme:extensions:request
        {action : install, update, remove or rollback}
        {package? : The Composer package, such as acme/link-suggestions}
        {--constraint= : The versions it may have, such as ^1.2}
        {--kind=plugin : plugin or theme}';

    /** @var string */
    protected $description = 'Ask the installer to install, update or remove a plugin or theme, or to roll back; the installer carries it out';

    public function handle(RequestExtensionChange $request): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $action = ExtensionAction::tryFrom((string) $this->argument('action'));
        $kind = ExtensionKind::tryFrom((string) $this->option('kind'));
        $package = $this->argument('package');
        $package = is_string($package) && $package !== '' ? $package : null;
        $constraint = $this->option('constraint');

        if ($action === null || ! in_array($action, self::ACTIONS, true)) {
            $this->line("“{$this->argument('action')}” isn’t a change to ask for: install, update, remove or rollback.");

            return self::FAILURE;
        }

        if ($kind === null) {
            $this->line("“{$this->option('kind')}” isn’t plugin or theme.");

            return self::FAILURE;
        }

        if ($action->namesPackage() && $package === null) {
            $this->line('Name the package, such as acme/link-suggestions.');

            return self::FAILURE;
        }

        try {
            $request->execute(new RequestExtensionChangeInput(
                $action,
                $kind,
                $action->namesPackage() ? $package : null,
                is_string($constraint) && $constraint !== '' ? $constraint : null,
            ));
        } catch (ValidationException $refusal) {
            $this->line(implode(' ', array_merge(...array_values($refusal->errors()))));

            return self::FAILURE;
        }

        $what = $action === ExtensionAction::Rollback ? 'Roll back' : ucfirst($action->value).' of '.$package;
        $this->line("{$what} requested. The installer carries it out within a minute.");

        return self::SUCCESS;
    }
}
