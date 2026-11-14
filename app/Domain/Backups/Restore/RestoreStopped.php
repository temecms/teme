<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use RuntimeException;

/**
 * A restore step that stopped; its message is the sentence people read, never a secret.
 */
final class RestoreStopped extends RuntimeException {}
