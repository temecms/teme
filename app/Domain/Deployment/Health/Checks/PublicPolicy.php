<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use App\Domain\Site\Policy\Reports\Queries\PolicyReportList;
use Carbon\CarbonImmutable;

/**
 * Says how the public site's content security policy is set and whether
 * browsers reported anything it blocked, or would block, in the last day
 * (I-3375).
 */
final readonly class PublicPolicy implements DoctorCheck
{
    public function __construct(private PolicyReportList $reports, private PolicyReportSpool $spool) {}

    public function title(): string
    {
        return __('Public site\'s security policy');
    }

    public function examine(): array
    {
        $findings = $this->modeFindings();
        $bad = count($this->spool->badFiles());

        if ($bad > 0) {
            $findings[] = new Finding(
                Severity::Warning,
                $bad === 1
                    ? __('A file of policy reports could not be recorded and was set aside.')
                    : __(':count files of policy reports could not be recorded and were set aside.', ['count' => $bad]),
                __('Look at the .bad files in the csp folder of the page cache folder, then delete them.'),
            );
        }

        return $findings;
    }

    /**
     * What the mode and the last day's reports say.
     *
     * @return list<Finding>
     */
    private function modeFindings(): array
    {
        $configured = PolicyMode::configured();
        $mode = PolicyMode::tryFrom($configured);

        if ($mode === PolicyMode::Off) {
            return [new Finding(app()->isProduction() ? Severity::Warning : Severity::Info, __('The public site sends no content security policy (TEME_PUBLIC_CSP=off).'))];
        }

        if ($mode === null) {
            return [new Finding(Severity::Warning, __('TEME_PUBLIC_CSP is “:value”, which Teme doesn\'t know, so the policy only reports. Use off, report-only or enforce.', ['value' => $configured]))];
        }

        $reported = $this->reports->countSince(CarbonImmutable::now()->subDay());

        if ($mode === PolicyMode::Enforce) {
            return [$reported === 0
                ? new Finding(Severity::Ok, __('Enforced, and nothing blocked in the last day.'))
                : new Finding(Severity::Warning, $reported === 1
                    ? __('The policy blocked one thing in the last day. See php artisan teme:csp:reports.')
                    : __('The policy blocked :count things in the last day. See php artisan teme:csp:reports.', ['count' => $reported]))];
        }

        return [$reported === 0
            ? new Finding(Severity::Ok, __('Report only, and nothing reported in the last day.'))
            : new Finding(Severity::Warning, $reported === 1
                ? __('One thing the policy would block was reported in the last day. See php artisan teme:csp:reports before you enforce it.')
                : __(':count things the policy would block were reported in the last day. See php artisan teme:csp:reports before you enforce it.', ['count' => $reported]))];
    }
}
