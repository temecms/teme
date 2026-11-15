<?php

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use Teme\Contracts\Import\ImportOutcome;
use Teme\Contracts\Import\WordPressRecord;
use Throwable;

/**
 * The plugins' own WordPress data (docs/proposals/plugin-import-socket.md):
 * for each plugin granted import.wordpress, in code order, each record of
 * its declared post types, people's meta and terms' meta, read a page at a
 * time and handed to its importer inside the record's own transaction with
 * its map row (I-332). After core's people, terms and content, so a plugin
 * can look up what they became; before the second pass.
 */
final readonly class PluginDataPhase implements ListPhase
{
    /** A post's fields a plugin is given, as the extension route sends them. */
    private const array FIELDS = ['guid', 'title', 'content', 'status', 'date_gmt', 'author', 'parent'];

    public function __construct(private PluginImports $imports, private ImportMap $map) {}

    public function key(): string
    {
        return 'plugin-data';
    }

    public function order(): int
    {
        return 550;
    }

    public function label(): string
    {
        return 'Plugins’ data';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        if (! PluginDataLists::answered($context->sourceId)) {
            return [];
        }

        $lists = [];

        foreach ($this->imports->all() as $import) {
            array_push($lists, ...PluginDataLists::of($import));
        }

        return $lists;
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return PluginDataLists::ref($list, $item);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        ['code' => $code, 'kind' => $kind, 'type' => $type, 'id' => $id] = PluginDataLists::parse($ref);
        $import = $this->imports->find($code)
            ?? throw new ImportFailed(ImportMessage::error('plugin-data.plugin-gone', 'The plugin this was read for no longer runs with access to WordPress data, so it wasn’t brought across.', ['plugin' => $code]));

        try {
            $importer = $import->importer();
        } catch (PluginImportRefused $refused) {
            throw new ImportFailed(ImportMessage::error('plugin-data.refused', $refused->getMessage(), ['plugin' => $code]));
        }

        $fields = $kind === WordPressRecord::POST ? array_intersect_key($item, array_flip(self::FIELDS)) : [];
        $meta = is_array($item['meta'] ?? null) ? array_map(fn (mixed $values): array => is_array($values) ? array_values($values) : [$values], $item['meta']) : [];

        try {
            $outcome = $importer->record(new WordPressRecord($kind, $id, $type, $fields, $meta), new RunScope($context, $this->map));
        } catch (Throwable $failure) {
            report($failure);

            throw new ImportFailed(ImportMessage::error('plugin-data.unexpected', "{$import->label} couldn’t bring this across because of an unexpected error. The application log has the details.", ['plugin' => $code]));
        }

        $result = match ($outcome->status) {
            ImportOutcome::FAILED => throw new ImportFailed(ImportMessage::error('plugin-data.failed', $outcome->words, ['plugin' => $code])),
            ImportOutcome::SKIPPED => ImportResult::skipped(new ImportMessage('plugin-data.skipped', $outcome->words, ['plugin' => $code])),
            default => $this->map->temeId($context->sourceId, $ref) === null ? ImportResult::created(PluginDataLists::TYPE, $code) : ImportResult::updated(PluginDataLists::TYPE, $code),
        };

        $objects = is_array($item['objects'] ?? null) ? array_values(array_filter($item['objects'], is_string(...))) : [];

        return $objects === [] ? $result : $result->withWarning(PluginRecordMessages::objects($import, $objects));
    }
}
