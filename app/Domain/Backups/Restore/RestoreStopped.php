<?php

/**
 * A restore step that stopped; its message is the sentence people read, never a secret.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use RuntimeException;

final class RestoreStopped extends RuntimeException {}
