<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsSetupCode;
use App\Domain\Deployment\DeployHistory;
use App\Domain\Deployment\DeploySite;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Queries\FirstRun;
use App\Domain\Users\SetupCode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * teme:deploy — brings this site to the release its code is, as the
 * operator (I-377): it checks the server, backs up when migrations wait,
 * migrates under a lock, and restarts what must restart. It never asks a
 * question, and with --json prints one line a program reads.
 */
final class RunDeploy extends Command
{
    use PrintsSetupCode;

    /** @var string */
    protected $signature = 'teme:deploy
        {--check : Only say whether migrations are waiting, changing nothing; exits 6 when they are}
        {--json : Print one line of JSON, and nothing else, for a program to read}
        {--wait=0 : The longest to wait for another deploy, or for a backup that is running, in seconds; 0 waits as long as it takes}';

    /** @var string */
    protected $description = 'Bring this site to the release its code is: check the server, back up, migrate, and restart what must restart';

    public function handle(DeploySite $site, DeployHistory $history, FirstRun $firstRun, SetupCode $setupCode): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $json = (bool) $this->option('json');
        $say = $json
            ? function (string $line): void {}
        : function (string $line): void {
            $this->line($line);
        };

        $started = CarbonImmutable::now();
        $report = (bool) $this->option('check')
            ? $site->check($say)
            : $site->run(max(0, (int) $this->option('wait')), $say, $json ? new NullOutput : $this->output);

        $history->record($report, $started);

        if ($json) {
            // Raw, so nothing in a sentence is read as a console style.
            $this->output->writeln(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->line($report->sentence);
        }

        // After the history is recorded, so the stored report never holds the code (issue 246).
        if (! $json && ! (bool) $this->option('check') && $report->outcome->exitCode() === 0 && $firstRun->needed()) {
            $this->printSetupCode($setupCode);
        }

        return $report->outcome->exitCode();
    }
}
