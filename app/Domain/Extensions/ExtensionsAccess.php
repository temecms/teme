<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * The permission that asks the installer to install, update, remove or roll
 * back plugins and themes, and keeps the sources and uploads they come from
 * (I-211). Administrators hold it.
 */
final class ExtensionsAccess
{
    public const string INSTALL = 'extensions.install';
}
