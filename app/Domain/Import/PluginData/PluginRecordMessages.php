<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\Phases\ImportMessage;

/**
 * The words a run's report shows about a plugin's data that every plugin
 * step says the same way.
 */
final readonly class PluginRecordMessages
{
    /**
     * WordPress kept a PHP object under these names, which Teme never reads
     * (rule 4), so the plugin was given null for each.
     *
     * @param  list<string>  $names
     */
    public static function objects(PluginImport $import, array $names): ImportMessage
    {
        $listed = implode(', ', $names);

        return new ImportMessage('plugin-data.object', "WordPress kept a PHP object in {$listed}, which Teme can’t read, so {$import->label} was given nothing there.", ['plugin' => $import->code, 'names' => $names]);
    }
}
