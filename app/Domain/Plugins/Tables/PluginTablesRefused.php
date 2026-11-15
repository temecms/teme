<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Tables;

use App\Domain\Plugins\FoundPlugin;
use RuntimeException;

/**
 * A plugin migration run was refused (I-3442): the migration isn't a
 * Migration, can't run in a transaction, or created or dropped a table that
 * isn't the plugin's. The sentence goes on the screen and the console.
 */
final class PluginTablesRefused extends RuntimeException
{
    public static function notAMigration(FoundPlugin $plugin, string $name): self
    {
        return new self("The migration {$name} of {$plugin->manifest?->label()} isn’t a Laravel migration.");
    }

    public static function outsideTransaction(FoundPlugin $plugin, string $name): self
    {
        return new self("The migration {$name} of {$plugin->manifest?->label()} can’t run outside a transaction.");
    }

    public static function created(FoundPlugin $plugin, string $name, string $table, string $why): self
    {
        return new self("The migration {$name} of {$plugin->manifest?->label()} created the table {$table}, which {$why}");
    }

    public static function dropped(FoundPlugin $plugin, string $name, string $table): self
    {
        return new self("The migration {$name} of {$plugin->manifest?->label()} dropped the table {$table}, which isn’t one of its tables.");
    }
}
