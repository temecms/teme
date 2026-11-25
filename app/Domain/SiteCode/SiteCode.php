<?php

/**
 * Settings › Site code (I-288): who may change it, the most code one entry
 * holds, the words for each placement, and the host's switch that keeps it
 * all off.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode;

final class SiteCode
{
    /** Administrators' (I-850). */
    public const string ABILITY = 'site-code.manage';

    /** The most characters one entry's code holds. */
    public const int MAX_LENGTH = 65535;

    /**
     * Each placement, keyed as the document shell's (App\Domain\Site\Document\Placement):
     * its short name, where it is, how a toast says where, the chip an entry
     * carries, and the line under its choice in the form.
     *
     * @var array<string, array{label: string, where: string, at: string, chip: string, hint: string}>
     */
    public const array PLACES = [
        'head' => ['label' => 'Head', 'where' => 'Inside <head>, on every public page.', 'at' => 'in the head', 'chip' => 'In the head of every page', 'hint' => 'Loads first. Suits analytics and verification tags.'],
        'body-start' => ['label' => 'Start of the body', 'where' => 'At the start of <body>, on every public page.', 'at' => 'at the start of the body', 'chip' => 'At the start of every page', 'hint' => 'Right after the page’s body opens. Suits the part of a tag manager that works without JavaScript.'],
        'body-end' => ['label' => 'End of the body', 'where' => 'At the end of <body>, on every public page.', 'at' => 'at the end of the body', 'chip' => 'At the foot of every page', 'hint' => 'Loads last, so the page shows first. Suits chat and pop-ups.'],
    ];

    /** Why no code changes while the host has switched site code off. */
    public const string SWITCHED_OFF = 'The host has switched site code off, so it can’t be changed here.';

    /**
     * Whether the host has switched site code off (TEME_DISALLOW_SITE_CODE):
     * then no page draws it, and nobody may change it. Read at each use.
     */
    public static function disallowed(): bool
    {
        return (bool) config('teme.disallow_site_code', false);
    }
}
