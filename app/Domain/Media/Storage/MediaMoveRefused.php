<?php

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use RuntimeException;

/**
 * A media move refused before it wrote anything, with why in words.
 */
final class MediaMoveRefused extends RuntimeException {}
