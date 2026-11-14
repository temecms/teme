<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Console\Command;

/**
 * teme:doctor — runs the doctor's checks and prints what each found with how
 * to fix it, most serious first (I-157). It exits 1 on any error, so a
 * deploy can refuse; it is the operator's tool and never needs the site to
 * start.
 */
final class RunDoctor extends Command
{
    /** @var string */
    protected $signature = 'teme:doctor';

    /** @var string */
    protected $description = "Check that this server can run Teme, and say how to fix what can't.";

    public function handle(Doctor $doctor): int
    {
        $errors = 0;
        $warnings = 0;

        foreach ($doctor->examine() as $result) {
            $finding = $result->finding;
            $errors += $finding->severity === Severity::Error ? 1 : 0;
            $warnings += $finding->severity === Severity::Warning ? 1 : 0;

            $this->line('  '.str_pad($finding->severity->label(), 8)." {$result->title}: {$finding->message}");

            if ($finding->fix !== null) {
                $this->line(str_repeat(' ', 11)."Fix: {$finding->fix}");
            }
        }

        $this->line('');
        $this->line($errors.($errors === 1 ? ' error' : ' errors').', '.$warnings.($warnings === 1 ? ' warning' : ' warnings').'.');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
