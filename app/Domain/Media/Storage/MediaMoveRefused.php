<?php

/**
 * A media move refused before it wrote anything, with why in words.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use RuntimeException;

final class MediaMoveRefused extends RuntimeException {}
