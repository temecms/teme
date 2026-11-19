<?php

/**
 * A plugin's list that answered with something other than rows of cells of
 * its declared kinds.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use RuntimeException;

final class ListAnswerRefused extends RuntimeException {}
