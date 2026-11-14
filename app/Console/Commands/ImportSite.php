<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * teme:import, the operator's import of a WordPress site (I-332): connects
 * it, asking for the application password without showing it, and starts a
 * full import or a catch-up; carries an import on, stops it, forgets the
 * site's password, or says how its last import went. A queue worker does
 * the work; this never waits for it.
 */
final class ImportSite extends Command
{
    /** @var string */
    protected $signature = 'teme:import
        {address : The WordPress site’s address, such as https://example.com}
        {--user= : A WordPress administrator’s user name, to connect the site}
        {--catch-up : Import only what changed since the last finished import}
        {--continue : Carry on the import that stopped}
        {--stop : Stop the import that is running}
        {--forget : Forget the site’s application password}
        {--status : Say how the last import went}
        {--pause=1000 : Milliseconds to wait between pages, to spare the old site}';

    /** @var string */
    protected $description = 'Import a WordPress site through the Teme Companion plugin';

    public function handle(ImportSources $sources, ImportRuns $runs, ImportPhases $phases): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $address = (string) $this->argument('address');
        $chosen = array_values(array_filter(['catch-up', 'continue', 'stop', 'forget', 'status'], fn (string $option): bool => (bool) $this->option($option)));

        if (count($chosen) > 1) {
            $this->error('Choose one of --catch-up, --continue, --stop, --forget and --status.');

            return self::FAILURE;
        }

        $action = $chosen[0] ?? 'start';
        $source = $sources->forAddress($address);

        if ($source === null && ! in_array($action, ['start', 'catch-up'], true)) {
            $this->error("Teme doesn’t know {$address} yet. Connect it first: php artisan teme:import {$address} --user=NAME");

            return self::FAILURE;
        }

        try {
            return match ($action) {
                'status' => $this->showStatus($source, $runs, $phases),
                'stop' => $this->stopImport($source, $runs),
                'continue' => $this->carryOn($source, $runs),
                'forget' => $this->forgetSite($source),
                default => $this->startImport($source, $address, $action === 'catch-up' ? ImportRunKind::CatchUp : ImportRunKind::Full, $sources),
            };
        } catch (ValidationException $refusal) {
            foreach (Arr::flatten($refusal->errors()) as $words) {
                $this->error((string) $words);
            }

            return self::FAILURE;
        }
    }

    /**
     * Connects the site when Teme doesn't keep its password (or a user name
     * is given), asking for the password with a hidden prompt, then starts.
     */
    private function startImport(?ImportSource $source, string $address, ImportRunKind $kind, ImportSources $sources): int
    {
        $user = $this->option('user');
        $pause = filter_var($this->option('pause'), FILTER_VALIDATE_INT);

        if ($pause === false) {
            $this->error('Choose a pause from 0 to 60,000 milliseconds.');

            return self::FAILURE;
        }

        if ($source === null || $source->credential === null || is_string($user)) {
            $user = is_string($user) && $user !== '' ? $user : $source?->user_name;

            if ($user === null || $user === '') {
                $this->error('Give a WordPress administrator’s user name with --user.');

                return self::FAILURE;
            }

            if (! $this->input->isInteractive()) {
                $this->error("Teme needs the application password for {$address}, and can’t ask for it without a terminal.");

                return self::FAILURE;
            }

            $password = (string) $this->secret("The application password for {$user} on {$address} (it isn’t shown as you type)");
            $connected = app(ConnectImportSource::class)->execute(new ConnectImportSourceInput($address, $user, $password, null));
            $source = $sources->find($connected['sourceId']) ?? throw new LogicException('A source just connected is missing.');
            $this->info("Connected to {$source->address} (Teme Companion {$source->plugin_version}).");
        }

        app(StartImportRun::class)->execute(new StartImportRunInput((string) $source->id, $kind, ['pause_ms' => $pause]));

        $this->info($kind === ImportRunKind::CatchUp
            ? "Started a catch-up of {$source->address}. A queue worker carries it out: see how it’s going with php artisan teme:import {$source->address} --status."
            : "Started importing {$source->address}. A queue worker carries it out: see how it’s going with php artisan teme:import {$source->address} --status.");

        return self::SUCCESS;
    }

    /**
     * Says how the site's latest import went, or that it has had none.
     */
    private function showStatus(ImportSource $source, ImportRuns $runs, ImportPhases $phases): int
    {
        $run = $runs->latest((string) $source->id);

        if ($run === null) {
            $this->line("{$source->address} hasn’t been imported yet.");

            return self::SUCCESS;
        }

        $this->line($run->kind->label().', '.strtolower($run->state->label()).'.');
        $started = $run->started_at->utc()->format('Y-m-d H:i');
        $this->line($run->ended_at === null
            ? "Started {$started} UTC."
            : "Started {$started} UTC, ended {$run->ended_at->utc()->format('Y-m-d H:i')} UTC.");
        $this->line("{$run->created_count} created, {$run->updated_count} updated, {$run->skipped_count} skipped, {$run->failed_count} failed, {$run->warning_count} with warnings.");

        if ($run->state === ImportRunState::Running) {
            $phase = $run->phase === null ? null : $phases->find($run->phase);
            $this->line('Now: '.($phase?->label() ?? $run->phase ?? '').'.');
        }

        if ($run->state === ImportRunState::Stopped && $run->stop_message !== null) {
            $this->line($run->stop_message);
        }

        return self::SUCCESS;
    }

    /**
     * Stops the import that is running.
     */
    private function stopImport(ImportSource $source, ImportRuns $runs): int
    {
        $run = $runs->running((string) $source->id);

        if ($run === null) {
            $this->error('This import isn’t running.');

            return self::FAILURE;
        }

        app(StopImportRun::class)->execute(new StopImportRunInput((string) $run->id, null));
        $this->info("Stopped the import of {$source->address}. Carry it on with php artisan teme:import {$source->address} --continue.");

        return self::SUCCESS;
    }

    /**
     * Carries on the import that stopped.
     */
    private function carryOn(ImportSource $source, ImportRuns $runs): int
    {
        $run = $runs->latest((string) $source->id);

        if ($run === null || $run->state !== ImportRunState::Stopped) {
            $this->error('Only an import that stopped can be carried on.');

            return self::FAILURE;
        }

        app(ContinueImportRun::class)->execute(new ContinueImportRunInput((string) $run->id, null));
        $this->info("Carrying on the import of {$source->address}.");

        return self::SUCCESS;
    }

    /**
     * Forgets the site's application password.
     */
    private function forgetSite(ImportSource $source): int
    {
        app(ForgetImportSource::class)->execute(new ForgetImportSourceInput((string) $source->id, null));
        $this->info("Teme has forgotten the application password for {$source->address}. Revoke it in WordPress too: Users › Profile › Application Passwords.");

        return self::SUCCESS;
    }
}
