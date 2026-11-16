<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Throwable;

/**
 * Before the plugins' records, once a run: each plugin's declared WordPress
 * options, read whole and handed to its importer (docs/proposals/plugin-import-socket.md).
 * A companion too old for plugin data is reported once, and every plugin
 * step skips; a plugin that fails is reported, and the run goes on.
 */
final readonly class PluginOptionsStep implements StepPhase
{
    public function __construct(private PluginImports $imports, private ImportMap $map) {}

    public function key(): string
    {
        return 'plugin-options';
    }

    public function order(): int
    {
        return 540;
    }

    public function label(): string
    {
        return 'Plugins’ settings';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $imports = $this->imports->all();

        if ($imports === []) {
            return StepResult::done();
        }

        if (! PluginDataLists::answered($context->sourceId)) {
            return StepResult::done([new ImportMessage('plugin-data.companion-too-old', 'The companion on this site is too old for plugin data, so no plugin’s data was brought across. Update the companion on WordPress, then catch up.')]);
        }

        $messages = [];

        foreach ($imports as $import) {
            if ($import->imports['options'] !== []) {
                array_push($messages, ...$this->hand($import, $context, $reader));
            }
        }

        return StepResult::done($messages);
    }

    /**
     * Reads one plugin's options and hands them over; the messages to report.
     *
     * @return list<ImportMessage>
     *
     * @throws SourceRefused
     */
    private function hand(PluginImport $import, ImportContext $context, SourceReader $reader): array
    {
        $names = $import->imports['options'];
        $answer = $reader->whole(PluginDataLists::ROUTE, ['plugin' => $import->code, 'kind' => 'options', 'names' => implode(',', $names)]);
        $read = $answer['options'] ?? null;
        $objects = $answer['objects'] ?? [];

        if (! is_array($read) || ! is_array($objects)) {
            throw SourceRefused::badAnswer();
        }

        $options = [];

        foreach ($names as $name) {
            $options[$name] = $read[$name] ?? null;
        }

        $messages = [];
        $objects = array_values(array_intersect($names, $objects));

        if ($objects !== []) {
            $messages[] = PluginRecordMessages::objects($import, $objects);
        }

        try {
            $import->importer()->options($options, new RunScope($context, $this->map));
        } catch (PluginImportRefused $refused) {
            $messages[] = ImportMessage::error('plugin-data.refused', $refused->getMessage(), ['plugin' => $import->code]);
        } catch (Throwable $failure) {
            report($failure);
            $messages[] = ImportMessage::error('plugin-data.options-failed', "{$import->label} couldn’t take its settings from WordPress because of an unexpected error. The application log has the details.", ['plugin' => $import->code]);
        }

        return $messages;
    }
}
