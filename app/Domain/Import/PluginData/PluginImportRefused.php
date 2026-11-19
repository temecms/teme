<?php

/**
 * A plugin's importer can't be used for this run: its message is the
 * sentence the run's report shows.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use RuntimeException;

final class PluginImportRefused extends RuntimeException {}
