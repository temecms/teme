<?php

/**
 * teme:extensions:cancel — cancels the plugin or theme request that waits, as the operator (I-211),
 * while the installer isn't carrying it out.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\Operations\CancelExtensionRequest;
use App\Domain\Extensions\Operations\Input\CancelExtensionRequestInput;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CancelExtensionCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:extensions:cancel';

    /** @var string */
    protected $description = 'Cancel the plugin or theme request that waits for the installer or for agreement';

    public function handle(ExtensionsScreen $extensions, CancelExtensionRequest $cancel): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $open = $extensions->openRequest();

        if ($open === null) {
            $this->line('No plugin or theme request is waiting.');

            return self::FAILURE;
        }

        try {
            $cancel->execute(new CancelExtensionRequestInput($open->id));
        } catch (ValidationException $refusal) {
            $this->line(implode(' ', array_merge(...array_values($refusal->errors()))));

            return self::FAILURE;
        }

        $what = $open->action === ExtensionAction::Rollback ? 'roll back' : "{$open->action->value} {$open->package}";
        $this->line("The request to {$what} was cancelled.");

        return self::SUCCESS;
    }
}
