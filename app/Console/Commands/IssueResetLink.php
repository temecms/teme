<?php

/**
 * `teme:user:reset-link`: prints a one-time password reset link for any
 * account. It acts as the operator, for when someone is locked out and email
 * is not working.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Operations\Input\IssuePasswordResetLinkInput;
use App\Domain\Users\Operations\IssuePasswordResetLink;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class IssueResetLink extends Command
{
    /** @var string */
    protected $signature = 'teme:user:reset-link {email : The account\'s email address}';

    /** @var string */
    protected $description = 'Print a one-time password reset link for an account';

    public function handle(IssuePasswordResetLink $issueLink): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $email = strtolower(trim((string) $this->argument('email')));

        try {
            $token = $issueLink->execute(new IssuePasswordResetLinkInput($email));
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->line('Reset link — it works once and expires in 60 minutes:');
        $this->line(rtrim((string) config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $email], false));

        return self::SUCCESS;
    }
}
