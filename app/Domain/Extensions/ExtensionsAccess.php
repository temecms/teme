<?php

/**
 * The permission that asks the installer to install, update, remove or roll
 * back plugins and themes, and keeps the sources and uploads they come from
 * (I-211). Administrators hold it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions;

final class ExtensionsAccess
{
    public const string INSTALL = 'extensions.install';
}
