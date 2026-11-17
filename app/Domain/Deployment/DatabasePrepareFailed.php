<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use RuntimeException;

/**
 * The database step couldn't do what it was asked. Its message is Teme's own sentence, with no password and
 * nothing the database said, since a driver's message may repeat what it was sent.
 */
final class DatabasePrepareFailed extends RuntimeException {}
