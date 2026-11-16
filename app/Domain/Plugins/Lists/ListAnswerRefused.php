<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use RuntimeException;

/**
 * A plugin's list that answered with something other than rows of cells of
 * its declared kinds.
 */
final class ListAnswerRefused extends RuntimeException {}
