<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use RuntimeException;

/**
 * The item's file was replaced after this work began, so the result belongs
 * to a file that is no longer current.
 */
final class StaleMediaException extends RuntimeException {}
