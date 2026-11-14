<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\UpdatePlan;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * teme:updates:request — asks for Teme to be updated, or rolled back to the release before, as the operator
 * (I-3326); every administrator is emailed and the updater carries it out.
 */
final class RequestUpdateCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:updates:request
        {version? : The release to update to}
        {--rollback : Roll back to the release before}';

    /** @var string */
    protected $description = 'Ask for Teme to be updated, or rolled back to the release before; the updater carries it out';

    public function handle(RequestTemeUpdate $request, UpdatePlan $plan): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $rollback = (bool) $this->option('rollback');
        $version = $this->argument('version');
        $version = is_string($version) ? $version : null;

        if ($rollback) {
            $version ??= $plan->previous();

            if ($version === null) {
                $this->line('There’s no release this site ran before to roll back to.');

                return self::FAILURE;
            }
        }

        if ($version === null) {
            throw new RuntimeException('Not enough arguments (missing: "version").');
        }

        try {
            $request->execute(new RequestTemeUpdateInput($version, $rollback ? UpdateKind::Rollback : UpdateKind::Update, null));
        } catch (ValidationException $refusal) {
            $this->line(implode(' ', array_merge(...array_values($refusal->errors()))));

            return self::FAILURE;
        }

        $this->line(($rollback ? 'Roll back' : 'Update')." to Teme {$version} requested. The updater starts it within a minute.");

        return self::SUCCESS;
    }
}
