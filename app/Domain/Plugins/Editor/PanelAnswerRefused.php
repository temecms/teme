<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use RuntimeException;

/**
 * A plugin's editor panel couldn't answer: it isn't handed over while the
 * plugin runs. Recorded under the plugin.
 */
final class PanelAnswerRefused extends RuntimeException {}
