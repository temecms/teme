<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use RuntimeException;

/**
 * Why a plugin's suggestion was dropped before the person saw it: it names
 * something the item doesn't have, or a change core won't make. Recorded
 * under the plugin, never shown.
 */
final class SuggestionRefused extends RuntimeException {}
