<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy;

/**
 * How the public site sends its content security policy (I-3375): not at
 * all, as a report that blocks nothing, or enforced. Read from deploy
 * configuration at each use; a value Teme doesn't know reads as
 * report-only, which breaks nothing, and the doctor names it.
 */
enum PolicyMode: string
{
    case Off = 'off';
    case ReportOnly = 'report-only';
    case Enforce = 'enforce';

    public static function current(): self
    {
        return self::tryFrom(self::configured()) ?? self::ReportOnly;
    }

    /** TEME_PUBLIC_CSP as written, trimmed and in lower case. */
    public static function configured(): string
    {
        return strtolower(trim((string) config('teme.public_csp')));
    }

    /** The header the policy goes out in; null when it is off. */
    public function header(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::ReportOnly => 'Content-Security-Policy-Report-Only',
            self::Enforce => 'Content-Security-Policy',
        };
    }
}
