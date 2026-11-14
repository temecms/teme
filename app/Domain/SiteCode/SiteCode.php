<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

/**
 * Settings › Site code (I-288): who may change it, the most code one entry
 * holds, the words for each placement, and the host's switch that keeps it
 * all off.
 */
final class SiteCode
{
    /** Administrators' (I-850). */
    public const string ABILITY = 'site-code.manage';

    /** The most characters one entry's code holds. */
    public const int MAX_LENGTH = 65535;

    /**
     * Each placement, keyed as the document shell's (App\Domain\Site\Document\Placement):
     * its panel's heading, where it is, and how a toast says where.
     *
     * @var array<string, array{label: string, where: string, at: string}>
     */
    public const array PLACES = [
        'head' => ['label' => 'Head', 'where' => 'Inside <head>, on every public page.', 'at' => 'in the head'],
        'body-start' => ['label' => 'Start of the body', 'where' => 'At the start of <body>, on every public page.', 'at' => 'at the start of the body'],
        'body-end' => ['label' => 'End of the body', 'where' => 'At the end of <body>, on every public page.', 'at' => 'at the end of the body'],
    ];

    /**
     * Whether the host has switched site code off (TEME_DISALLOW_SITE_CODE):
     * then no page draws it, and nobody may change it. Read at each use.
     */
    public static function disallowed(): bool
    {
        return (bool) config('teme.disallow_site_code', false);
    }
}
