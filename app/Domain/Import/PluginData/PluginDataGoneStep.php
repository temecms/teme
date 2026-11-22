<?php

/**
 * A catch-up's list of the plugins' records WordPress no longer has
 * (I-345): each list read again a page at a time and compared with the
 * map. Nothing is deleted, in Teme or in a plugin's own tables; the plugin
 * keeps what it has. Forty seconds a job.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Import\WordPressRecord;

final readonly class PluginDataGoneStep implements StepPhase
{
    /** Seconds a job reads before it hands on to the next. */
    public const int BUDGET = 40;

    /** Records listed before the rest are only counted. */
    public const int MOST_SAID = 500;

    /** What each record kind is called in the words. */
    private const array NOUNS = [WordPressRecord::POST => 'post', WordPressRecord::USER => 'person', WordPressRecord::TERM => 'term'];

    public function __construct(private PluginImports $imports) {}

    public function key(): string
    {
        return 'plugin-data-gone';
    }

    public function order(): int
    {
        return 560;
    }

    public function label(): string
    {
        return 'Plugins’ data deleted on WordPress';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::CatchUp;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // An export file has none of this (docs/architecture/import.md).
        if ($context->readsFile()) {
            return StepResult::done();
        }

        if (! PluginDataLists::answered($context->sourceId)) {
            return StepResult::done();
        }

        $lists = [];

        foreach ($this->imports->all() as $import) {
            foreach (PluginDataLists::of($import) as $list) {
                $lists[] = [$import, $list];
            }
        }

        [$index, $after, $said] = array_map(intval(...), array_pad(explode(':', (string) $cursor), 3, '0'));
        $started = microtime(true);
        $messages = [];

        while (isset($lists[$index])) {
            if (microtime(true) - $started > self::BUDGET) {
                return StepResult::continueAt("{$index}:{$after}:{$said}", $messages);
            }

            [$import, $list] = $lists[$index];
            [$gone, $next] = $this->page($import, $list, $context, $reader, $after);

            foreach ($gone as $id) {
                if (++$said <= self::MOST_SAID) {
                    $messages[] = $this->said($import, $list, $id);
                }
            }

            if ($next !== null) {
                $after = $next;

                continue;
            }

            if ($said > self::MOST_SAID) {
                $more = $said - self::MOST_SAID;
                $messages[] = new ImportMessage('plugin-data.gone-more', "WordPress no longer has {$more} more of {$import->label}’s records. Nothing was changed.", ['plugin' => $import->code, 'more' => $more]);
            }

            [$index, $after, $said] = [$index + 1, 0, 0];
        }

        return StepResult::done($messages);
    }

    /**
     * The WordPress IDs above $after and up to the page's last that the map
     * holds for this list and the page doesn't; and the ID to carry on
     * from, null at the list's end.
     *
     * @return array{0: list<int>, 1: int|null}
     *
     * @throws SourceRefused
     */
    private function page(PluginImport $import, ImportList $list, ImportContext $context, SourceReader $reader, int $after): array
    {
        $page = $reader->page($list->name, $list->query, $after, $list->limit);
        $listed = [];

        foreach ($page->items as $item) {
            $listed[(int) ($item['id'] ?? 0)] = true;
        }

        $prefix = implode(':', array_slice(explode(':', PluginDataLists::ref($list, ['id' => 0])->id), 0, PluginDataLists::kindOf($list) === WordPressRecord::TERM ? 2 : 3)).':';
        $number = "split_part(object_id, ':', 4)::bigint";

        $mapped = DB::table('import_map')
            ->where('source_id', $context->sourceId)->where('object_type', PluginDataLists::TYPE)->whereNotNull('teme_id')
            ->where('object_id', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->whereRaw("{$number} > ?", [$after])
            ->when($page->nextAfterId !== null, fn ($rows) => $rows->whereRaw("{$number} <= ?", [$page->nextAfterId]))
            ->orderByRaw($number)
            ->pluck('object_id')->all();

        $gone = [];

        foreach ($mapped as $objectId) {
            $id = (int) substr((string) $objectId, (int) strrpos((string) $objectId, ':') + 1);

            if (! isset($listed[$id])) {
                $gone[] = $id;
            }
        }

        return [$gone, $page->nextAfterId];
    }

    private function said(PluginImport $import, ImportList $list, int $id): ImportMessage
    {
        $kind = PluginDataLists::kindOf($list);
        $noun = self::NOUNS[$kind];

        // A person's or term's list holds only those with the declared meta, so the meta is what's gone, not them.
        $what = $kind === WordPressRecord::POST
            ? "the {$noun} {$id} whose data {$import->label} brought across"
            : "the data {$import->label} declared for the {$noun} {$id}";

        return new ImportMessage('plugin-data.gone', "WordPress no longer has {$what}. Nothing was changed: {$import->label} keeps what it has.", ['plugin' => $import->code, 'kind' => $kind, 'wordpress_id' => $id]);
    }
}
