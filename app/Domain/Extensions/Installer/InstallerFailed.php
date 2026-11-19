<?php

/**
 * A step of the installer couldn't go on: its message is the reason the
 * request records and the screen shows, and never holds a key.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use RuntimeException;

final class InstallerFailed extends RuntimeException {}
