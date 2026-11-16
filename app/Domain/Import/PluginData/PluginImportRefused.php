<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use RuntimeException;

/**
 * A plugin's importer can't be used for this run: its message is the
 * sentence the run's report shows.
 */
final class PluginImportRefused extends RuntimeException {}
