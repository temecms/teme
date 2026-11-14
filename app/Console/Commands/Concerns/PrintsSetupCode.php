<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Users\SetupCode;

/**
 * The lines that tell an operator how to make the first administrator from
 * the browser (issue 246), shared by `teme:deploy` and `teme:setup-code`.
 */
trait PrintsSetupCode
{
    /**
     * The setup page's address, the code on a line of its own (never in the
     * address, which a proxy's log would keep), and the console way.
     */
    protected function printSetupCode(SetupCode $code): void
    {
        $this->line('No one can sign in yet. To make the first administrator, open '.route('first-run.show').' and enter this setup code:');
        $this->line('  '.$code->forThisSite());
        $this->line('Or run php artisan teme:user:create.');
    }
}
