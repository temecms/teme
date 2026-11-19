<?php

/**
 * Thrown when a backup, a prune or a restore would start while another holds the backup lock (I-358).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

final class BackupAlreadyRunning extends RuntimeException {}
