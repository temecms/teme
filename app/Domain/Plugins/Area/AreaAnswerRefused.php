<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use RuntimeException;

/**
 * A plugin's area answered with something core can't draw: the wrong type,
 * an unknown key, or a value of the wrong kind.
 */
final class AreaAnswerRefused extends RuntimeException {}
