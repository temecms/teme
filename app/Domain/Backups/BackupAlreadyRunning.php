<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * Thrown when a backup, a prune or a restore would start while another holds the backup lock (I-358).
 */
final class BackupAlreadyRunning extends RuntimeException {}
