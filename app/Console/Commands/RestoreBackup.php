<?php

/**
 * teme:backup:restore — restores the site from a backup, inline, as the operator (docs/architecture/operations.md): it shows what it will do and asks, then says each step, and asks after whether this server is now the site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NamesRestorePlace;
use App\Domain\Backups\Operations\ConfirmRestoredSite;
use App\Domain\Backups\Operations\Input\ConfirmRestoredSiteInput;
use App\Domain\Backups\Restore\RestorePlan;
use App\Domain\Backups\Restore\RestoreRefused;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Backups\RestoredSiteAnswer;
use App\Domain\Backups\Tools\SshKeyscan;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class RestoreBackup extends Command
{
    use NamesRestorePlace;

    private const string SITE = 'This server is now the site';

    private const string COPY = 'This is a copy: remove these backup places';

    private const string LATER = 'Decide later';

    /** @var string */
    protected $signature = 'teme:backup:restore {run? : The backup’s run ID, from teme:backup:list; the newest complete one when left out} {--place= : The place, by name or ID} {--without-safety-backup : Replace a site with data without backing it up first} {--force : Restore without asking}'.self::PLACE_OPTIONS;

    /** @var string */
    protected $description = 'Restore the site from a backup: its database and media, after checking the backup can be restored here';

    public function handle(RestoreSite $site, ConfirmRestoredSite $confirm, SshKeyscan $keyscan): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $force = (bool) $this->option('force');

        if (! $this->input->isInteractive() && ! $force) {
            $this->error('This replaces the whole site, so it needs a person at the terminal to confirm, or --force.');

            return self::FAILURE;
        }

        $say = fn (string $step) => $this->line($step);
        $without = (bool) $this->option('without-safety-backup');
        $named = $this->namedPlace($keyscan, $force);

        if ($named === false) {
            return self::FAILURE;
        }

        ['from' => $from, 'key' => $key] = $named;

        try {
            $plan = $site->preflight(new RestoreRequest($this->stringOption('place'), $this->stringArgument('run'), $without, $from, $key), $say);
        } catch (RestoreRefused $refused) {
            return $this->refused($refused);
        }

        $this->show($plan, $without);

        if (! $force && ! $this->confirm('Restore this backup?', false)) {
            $this->error('Nothing was restored.');

            return self::FAILURE;
        }

        try {
            $result = $site->run(new RestoreRequest($from === null ? $plan->place : null, $plan->runId, $without, $from, $key), $say);
        } catch (RestoreRefused $refused) {
            return $this->refused($refused);
        }

        if (! $result->succeeded) {
            $this->error($result->sentence);

            foreach ($result->notes as $note) {
                $this->warn($note);
            }

            return self::FAILURE;
        }

        $this->line($result->sentence);

        foreach ($result->notes as $note) {
            $this->warn($note);
        }

        $this->line('Backups are paused: the restored site’s places are the old site’s too, and two sites must never back up into one place.');
        $this->askAboutThePause($confirm, $force);

        return self::SUCCESS;
    }

    private function show(RestorePlan $plan, bool $without): void
    {
        $this->line("This replaces the site’s database and media with the backup of {$plan->made} from {$plan->place}: Teme {$plan->teme}, "
            .number_format($plan->tables).' tables, '.number_format($plan->rows).' rows and '
            .($plan->mediaFiles === 1 ? '1 media file.' : number_format($plan->mediaFiles).' media files.'));

        if ($plan->safetyBackup) {
            $this->line('First, Teme backs the site up as it is now, so the restore can be undone.');
        } elseif ($without) {
            $this->line('No safety backup: --without-safety-backup.');
        } elseif ($plan->unfinished === null) {
            $this->line('This server’s database holds nothing yet, so there’s nothing to back up first.');
        }

        foreach ($plan->notes as $note) {
            $this->warn($note);
        }
    }

    private function refused(RestoreRefused $refused): int
    {
        foreach ($refused->problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    private function askAboutThePause(ConfirmRestoredSite $confirm, bool $force): void
    {
        $say = 'Say which with php artisan teme:backup:restored site, or php artisan teme:backup:restored copy.';

        if ($force || ! $this->input->isInteractive()) {
            $this->line($say);

            return;
        }

        $answer = $this->choice('Is this server now the site?', [self::SITE, self::COPY, self::LATER], 2);

        if ($answer === self::LATER) {
            $this->line($say);

            return;
        }

        $names = $confirm->execute(new ConfirmRestoredSiteInput($answer === self::SITE ? RestoredSiteAnswer::ThisIsTheSite : RestoredSiteAnswer::ThisIsACopy));
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : $names[0];

        $this->line($answer === self::SITE
            ? "Backups resume at {$list}."
            : "Removed {$list} from this site’s backup places. Their backups stay where they are. Set backups up for this copy with php artisan teme:backup:setup.");
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
