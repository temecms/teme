<?php

declare(strict_types=1);

namespace App\Domain\Import\Planning;

/**
 * The Teme Companion plugin as Tools › Import names it: the version Teme expects, and where it is published (I-1711).
 */
final class CompanionPlugin
{
    /** The first version with the export API Teme reads. */
    public const string VERSION = '0.2.0';

    // Later (I-1711): the public repository's releases address, once the maintainers publish it.
    public const ?string RELEASES = null;
}
