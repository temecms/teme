<?php

/**
 * `teme:setup-code`: prints the first-run page's address and setup code again
 * (issue 246), for an operator who lost the deploy log. It changes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsSetupCode;
use App\Domain\Users\Queries\FirstRun;
use App\Domain\Users\SetupCode;
use Illuminate\Console\Command;

final class ShowSetupCode extends Command
{
    use PrintsSetupCode;

    /** @var string */
    protected $signature = 'teme:setup-code';

    /** @var string */
    protected $description = 'Print the first-run page\'s address and setup code, while no one can sign in yet';

    public function handle(FirstRun $firstRun, SetupCode $setupCode): int
    {
        if (! $firstRun->needed()) {
            $this->info('This site is already set up, so its setup page is closed. To add someone, run php artisan teme:user:create, or invite them in the admin.');

            return self::SUCCESS;
        }

        if ((string) config('app.key') === '') {
            $this->error('The application key is missing, so there is no setup code. Set APP_KEY, then run this again.');

            return self::FAILURE;
        }

        $this->printSetupCode($setupCode);

        return self::SUCCESS;
    }
}
