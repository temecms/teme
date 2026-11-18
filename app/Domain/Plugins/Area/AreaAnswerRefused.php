<?php

/**
 * A plugin's area answered with something core can't draw: the wrong type,
 * an unknown key, or a value of the wrong kind.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use RuntimeException;

final class AreaAnswerRefused extends RuntimeException {}
