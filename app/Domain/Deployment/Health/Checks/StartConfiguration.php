<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\StartRules;

/**
 * Reports each rule a production site must meet to start (I-375): the
 * start rules' own sentences, an error in production and a warning
 * elsewhere, where the site starts regardless.
 */
final readonly class StartConfiguration implements DoctorCheck
{
    public function title(): string
    {
        return 'Production settings';
    }

    public function examine(): array
    {
        $problems = StartRules::problems(app('config'));

        if ($problems === []) {
            return [new Finding(Severity::Ok, 'APP_KEY, APP_URL, the session driver, cookies and TEME_HSTS are as a production site needs.')];
        }

        $severity = Severity::inProduction(app()->isProduction());

        return array_map(fn (string $problem): Finding => new Finding($severity, $problem), $problems);
    }
}
