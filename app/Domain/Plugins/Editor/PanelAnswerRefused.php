<?php

/**
 * A plugin's editor panel couldn't answer: it isn't handed over while the
 * plugin runs. Recorded under the plugin.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use RuntimeException;

final class PanelAnswerRefused extends RuntimeException {}
