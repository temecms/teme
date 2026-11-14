<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;

/**
 * Says whether a production site's email is really sent (I-378): the log
 * and array mailers keep every password reset and alert to themselves.
 */
final readonly class Mailer implements DoctorCheck
{
    public function title(): string
    {
        return 'Email';
    }

    public function examine(): array
    {
        $mailer = (string) config('mail.default');

        if (app()->isProduction() && in_array($mailer, ['log', 'array'], true)) {
            return [new Finding(Severity::Warning, "MAIL_MAILER is {$mailer}, so password resets, invitations and backup alerts are never sent.", 'Set MAIL_MAILER and its settings for a real mail service.')];
        }

        return [new Finding(Severity::Ok, "Email goes through {$mailer}.")];
    }
}
