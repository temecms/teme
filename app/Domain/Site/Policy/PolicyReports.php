<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy;

/**
 * Where browsers send the public site's policy reports (I-3375): one
 * address in core's reserved space, which the page cache passes by, and
 * the name the policy's report-to gives it.
 */
final class PolicyReports
{
    public const string PATH = '/_teme/csp';

    public const string GROUP = 'teme-csp';

    /** The report address, absolute, from the site's own. */
    public static function url(): string
    {
        return url(self::PATH);
    }

    /** The Reporting-Endpoints header's value. */
    public static function endpointsHeader(): string
    {
        return self::GROUP.'="'.self::url().'"';
    }
}
