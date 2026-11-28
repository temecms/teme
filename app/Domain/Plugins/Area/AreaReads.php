<?php

/**
 * Reads a plugin's area for its pages (docs/plans/plugin-sockets-2.md, A7):
 * each call under 250 ms and all of one request's under a second, every
 * answer cleaned to plain values. A call that throws, runs over or answers
 * wrongly is recorded under the plugin, and its block shows one line
 * instead; the rest of the page works.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Columns\CellText;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\Operations\RunPluginAction;
use Closure;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\HasAreaCounts;
use Teme\Contracts\Admin\HasRecordHeader;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\RecordHeader;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Lists\Cell;
use Throwable;

final class AreaReads
{
    public const int CALL_BUDGET_MS = 250;

    public const int PAGE_BUDGET_MS = 1_000;

    /** The longest figure text and chart label, in characters. */
    public const int MOST_FIGURE_TEXT = 40;

    public const int MOST_CHART_LABEL = 24;

    /** The most table pages a person may ask for, and the longest search. */
    public const int MOST_PAGE = 1_000;

    public const int MOST_SEARCH = 100;

    /** The largest tab count drawn; above it the tab shows no number. @since next */
    public const int MOST_COUNT = 1_000_000;

    /** The most unavailable action ids kept on one row. @since next */
    public const int MOST_UNAVAILABLE = 20;

    private int $spent = 0;

    public function __construct(
        private readonly ColumnClock $clock,
        private readonly Container $container,
    ) {}

    /**
     * A figures block's items, each its id, label, format, value (null when
     * the plugin gave none) and change; or the line saying it couldn't be shown.
     * An empty answer from the plugin is the "No statistics yet" state.
     *
     * @param  array<string, mixed>  $block
     *
     * @return array{items: list<array{id: string, label: string, format: string, value: string|int|float|null, change: float|null}>, failed: string|null, empty?: bool}
     */
    public function figures(AreaPlugin $plugin, string $page, array $block, AreaContext $context): array
    {
        $id = is_string($block['id'] ?? null) ? $block['id'] : '';
        $given = $this->call($plugin, "figures {$page}/{$id}", fn (PluginArea $data): array => $data->figures($page, $id, $context));

        if ($given === null) {
            return ['items' => [], 'failed' => $this->failure($plugin)];
        }

        // An empty answer is "No statistics yet" only when the block declares
        // its empty line; otherwise each figure shows a dash (the old meaning).
        if (is_array($given) && $given === [] && is_string($block['empty'] ?? null) && trim($block['empty']) !== '') {
            return ['items' => [], 'failed' => null, 'empty' => true];
        }

        $items = [];
        $refused = ! is_array($given) && $given !== [];
        $index = 0;

        foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }

            $figure = is_array($given) ? ($given[$item['id']] ?? null) : null;
            $format = is_string($item['format'] ?? null) ? $item['format'] : Figure::NUMBER;
            $value = null;
            $change = null;

            if ($figure instanceof Figure && $figure->format === $format) {
                $value = $format === Figure::TEXT ? CellText::clean((string) $figure->value, self::MOST_FIGURE_TEXT) : $figure->value;
                $change = $figure->change;
            } elseif ($figure !== null) {
                $refused = true;
            }

            $label = AreaWords::text($plugin->code, is_string($item['label'] ?? null) ? $item['label'] : '');
            // The noun for screen readers: the label before a trailing ", …" period phrase.
            $thing = str_contains($label, ',')
                ? trim((string) strstr($label, ',', true))
                : $label;

            if ($index === 0 && $context->period !== null && $thing !== '') {
                $label = $thing.', '.AreaPeriods::lower($context->period->id);
            }

            $items[] = [
                'id' => $item['id'],
                'label' => $label,
                'format' => $format,
                'value' => $value === '' ? null : $value,
                'change' => $change,
                'thing' => $thing !== '' ? mb_strtolower($thing) : null,
                'before' => $context->period !== null ? AreaPeriods::before($context->period->id) : null,
            ];
            $index++;
        }

        if ($refused) {
            $this->fault($plugin, new AreaAnswerRefused("The figures {$page}/{$id} answered with the wrong shape."));
        }

        return ['items' => $items, 'failed' => null];
    }

    /**
     * A chart block's labels and its declared series, each with its values;
     * or the line saying it couldn't be shown. Earlier-period series from
     * the plugin are drawn dashed with core's comparison label.
     *
     * @param  array<string, mixed>  $block
     *
     * @return array{labels: list<string>, series: list<array{id: string, label: string, values: list<int|float>, dashed: bool}>, failed: string|null, empty?: bool}
     */
    public function chart(AreaPlugin $plugin, string $page, array $block, AreaContext $context): array
    {
        $id = is_string($block['id'] ?? null) ? $block['id'] : '';
        $given = $this->call($plugin, "chart {$page}/{$id}", fn (PluginArea $data): Chart => $data->chart($page, $id, $context));

        if (! $given instanceof Chart) {
            return ['labels' => [], 'series' => [], 'failed' => $this->failure($plugin)];
        }

        if ($given->labels === [] && $given->series === []) {
            return ['labels' => [], 'series' => [], 'failed' => null, 'empty' => true];
        }

        $series = [];
        $before = $context->period !== null ? AreaPeriods::beforeLabel($context->period->id) : null;

        foreach (is_array($block['series'] ?? null) ? $block['series'] : [] as $one) {
            if (! is_array($one) || ! is_string($one['id'] ?? null)) {
                continue;
            }

            $series[] = [
                'id' => $one['id'],
                'label' => AreaWords::text($plugin->code, is_string($one['label'] ?? null) ? $one['label'] : ''),
                'values' => $given->series[$one['id']] ?? array_fill(0, count($given->labels), 0),
                'dashed' => false,
            ];

            if ($before !== null && array_key_exists($one['id'], $given->compare)) {
                $series[] = [
                    'id' => $one['id'].'__before',
                    'label' => $before,
                    'values' => $given->compare[$one['id']],
                    'dashed' => true,
                ];
            }
        }

        return [
            'labels' => array_map(fn (string $label): string => (string) CellText::clean($label, self::MOST_CHART_LABEL), $given->labels),
            'series' => $series,
            'failed' => null,
        ];
    }

    /**
     * A record's header for its form page, or null when the plugin supplies
     * none or the read fails.
     *
     * @return array{status: string|null, tone: string, meta: list<string>}|null
     */
    public function header(AreaPlugin $plugin, string $record, string $id, AreaContext $context): ?array
    {
        if (! $plugin->data instanceof HasRecordHeader) {
            return null;
        }

        $given = $this->call($plugin, "header {$record}", fn (PluginArea $data): ?RecordHeader => $data instanceof HasRecordHeader ? $data->header($record, $id, $context) : null);

        if (! $given instanceof RecordHeader) {
            return null;
        }

        $meta = [];

        foreach (array_slice($given->meta, 0, 3) as $line) {
            $clean = CellText::clean($line, PluginColumns::MOST_TEXT);

            if ($clean !== null && $clean !== '') {
                $meta[] = $clean;
            }
        }

        $status = $given->status === null ? null : CellText::clean($given->status, PluginColumns::MOST_BADGE);

        return [
            'status' => $status === '' ? null : $status,
            'tone' => $given->tone,
            'meta' => $meta,
        ];
    }

    /**
     * Tab counts by page id, or null when the plugin has none or the read fails.
     *
     * @return array<string, int>|null
     */
    public function counts(AreaPlugin $plugin, AreaContext $context): ?array
    {
        if (! $plugin->data instanceof HasAreaCounts) {
            return null;
        }

        $given = $this->call($plugin, 'counts', fn (PluginArea $data): array => $data instanceof HasAreaCounts ? $data->counts($context) : []);

        if (! is_array($given)) {
            return null;
        }

        $counts = [];

        foreach ($plugin->area->pages as $page) {
            $value = $given[$page['id']] ?? null;

            if (is_int($value) && $value >= 0 && $value < self::MOST_COUNT) {
                $counts[$page['id']] = $value;
            }
        }

        return $counts;
    }

    /**
     * One page of a table's rows, each its id, its cleaned cells and the
     * actions not offered on it, with the total when the plugin knows it;
     * or the line saying it couldn't be shown.
     *
     * @param  array<string, mixed>  $table
     *
     * @return array{rows: list<array{id: string, cells: array<string, array{kind: string, value: string|int|float, tone?: string}>, unavailable: list<string>, queued: list<string>}>, total: int|null, failed: string|null}
     */
    public function rows(AreaPlugin $plugin, array $table, TableQuery $query, AreaContext $context): array
    {
        $record = is_string($table['record'] ?? null) ? $table['record'] : '';
        $given = $this->call($plugin, "rows {$record}", fn (PluginArea $data): RowPage => $data->rows($record, $query, $context));

        if (! $given instanceof RowPage) {
            return ['rows' => [], 'total' => null, 'failed' => $this->failure($plugin)];
        }

        $kinds = [];

        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['id'] ?? null) && is_string($column['kind'] ?? null)) {
                $kinds[$column['id']] = $column['kind'];
            }
        }

        $custom = array_values(array_filter(
            is_array($table['rowActions'] ?? null) ? $table['rowActions'] : [],
            fn (mixed $id): bool => is_string($id) && ! in_array($id, AreaValidator::ROW_ACTIONS, true),
        ));

        $rows = [];
        $refused = false;

        foreach (array_slice($given->rows, 0, $query->perPage) as $row) {
            $kept = $this->row($row, $kinds, $refused);

            if ($kept !== null) {
                $rows[] = $kept;
            }
        }

        if ($refused || count($given->rows) > $query->perPage) {
            $this->fault($plugin, new AreaAnswerRefused("The rows of {$record} answered with the wrong shape."));
        }

        $queued = $this->queuedByRow($custom, array_column($rows, 'id'));

        foreach ($rows as $index => $row) {
            $rows[$index]['queued'] = $queued[$row['id']] ?? [];
        }

        return ['rows' => $rows, 'total' => $given->total, 'failed' => null];
    }

    /**
     * One row as the page draws it, or null when the plugin answered
     * something else; a cell of another kind or an unknown column is left
     * out, and $refused set.
     *
     * @param  mixed  $row  what the plugin put in its page of rows
     * @param  array<string, string>  $kinds  each column's kind, by its id
     *
     * @return array{id: string, cells: array<string, array{kind: string, value: string|int|float, tone?: string, href?: string|null, host?: string|null}>, unavailable: list<string>}|null
     */
    private function row(mixed $row, array $kinds, bool &$refused): ?array
    {
        if (! $row instanceof Row) {
            $refused = true;

            return null;
        }

        $cells = [];

        foreach ($row->cells as $column => $cell) {
            $kind = $kinds[$column] ?? null;
            $kept = $kind === null ? false : $this->cell($cell, $kind);

            if ($kept === false) {
                $refused = true;
            } elseif ($kept !== null) {
                $cells[$column] = $kept;
            }
        }

        $unavailable = array_values(array_filter($row->unavailable, is_string(...)));

        return [
            'id' => $row->id,
            'cells' => $cells,
            'unavailable' => array_slice($unavailable, 0, self::MOST_UNAVAILABLE),
            'queued' => [],
        ];
    }

    /**
     * Custom action ids with a waiting or running job for each row, keyed by
     * record id.
     *
     * @param  list<string>  $actions
     * @param  list<string>  $recordIds
     *
     * @return array<string, list<string>>
     */
    private function queuedByRow(array $actions, array $recordIds): array
    {
        if ($actions === [] || $recordIds === []) {
            return [];
        }

        $keys = [];

        foreach ($recordIds as $recordId) {
            foreach ($actions as $action) {
                $keys[RunPluginAction::uniqueKey($action, $recordId)] = [$recordId, $action];
            }
        }

        $found = [];

        foreach (TrackedJob::query()
            ->whereIn('state', [JobState::Waiting, JobState::Running])
            ->whereIn('unique_key', array_keys($keys))
            ->pluck('unique_key') as $key) {
            if (! is_string($key) || ! isset($keys[$key])) {
                continue;
            }

            [$recordId, $action] = $keys[$key];
            $found[$recordId][] = $action;
        }

        return $found;
    }

    /**
     * One cell, cleaned: false when it isn't a cell of its column's kind,
     * null when no text is left. A link keeps only an https address with
     * no user or password; anything else is drawn as no link.
     *
     * @param  mixed  $cell  what the plugin put in the row
     *
     * @return array{kind: string, value: string|int|float, tone?: string, href?: string|null, host?: string|null}|false|null
     */
    private function cell(mixed $cell, string $kind): array|false|null
    {
        if (! $cell instanceof Cell || $cell->kind !== $kind) {
            return false;
        }

        if ($kind === Cell::NUMBER) {
            return ['kind' => Cell::NUMBER, 'value' => $cell->value];
        }

        $clean = CellText::clean((string) $cell->value, $kind === Cell::BADGE ? PluginColumns::MOST_BADGE : PluginColumns::MOST_TEXT);

        if ($clean === null || $clean === '') {
            return null;
        }

        if ($kind === Cell::BADGE) {
            return ['kind' => Cell::BADGE, 'value' => $clean, 'tone' => $cell->tone ?? 'neutral'];
        }

        if ($kind === Cell::LINK) {
            $href = $this->safeHref($cell->href);
            $host = $href === null ? null : parse_url($href, PHP_URL_HOST);

            return [
                'kind' => Cell::LINK,
                'value' => $clean,
                'href' => $href,
                'host' => is_string($host) && $host !== '' ? $host : null,
            ];
        }

        return ['kind' => Cell::TEXT, 'value' => $clean];
    }

    /**
     * An https address with a host and no user or password, or null.
     */
    private function safeHref(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }

        $parts = parse_url($href);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return null;
        }

        return $href;
    }

    /**
     * A record's stored values for its form, only its declared fields, each
     * of its field's kind; a value of the wrong kind reads as empty and is
     * recorded once. Null when there is no such record, or the plugin
     * couldn't say; then $failed is set.
     *
     * @param  list<array<string, mixed>>  $fields  as DeclaredArea::fields() gives them
     *
     * @return array<string, mixed>|null
     */
    public function values(AreaPlugin $plugin, string $record, string $id, array $fields, AreaContext $context, ?string &$failed = null): ?array
    {
        $failed = null;
        $called = false;
        $given = $this->call($plugin, "values {$record}", function (PluginArea $data) use ($record, $id, $context, &$called): ?array {
            $called = true;

            return $data->values($record, $id, $context);
        }, $called);

        if ($given === null && ! $called) {
            $failed = $this->failure($plugin);

            return null;
        }

        if (! is_array($given)) {
            return null;
        }

        [$kept, $refused] = AreaForms::kept($fields, $given);

        if ($refused) {
            $this->fault($plugin, new AreaAnswerRefused("The values of {$record} answered with the wrong shape."));
        }

        return $kept;
    }

    /**
     * The line a block shows when the plugin couldn't answer.
     */
    public function failure(AreaPlugin $plugin): string
    {
        return __('This part of :plugin couldn’t be shown this time.', ['plugin' => $plugin->label]);
    }

    /**
     * Calls the plugin's area under the budgets; null when it threw, ran
     * over, wasn't running, or the page's budget was spent. $answered says
     * whether the call came back in time, for a call whose answer may be null.
     *
     * @param  Closure(PluginArea): mixed  $call
     */
    private function call(AreaPlugin $plugin, string $what, Closure $call, bool &$answered = false): mixed
    {
        $answered = false;

        if ($plugin->data === null) {
            $this->fault($plugin, new AreaAnswerRefused('The plugin isn’t running, or has no area to read.'));

            return null;
        }

        if ($this->spent >= self::PAGE_BUDGET_MS * 1_000_000) {
            $this->fault($plugin, new AreaTooSlow($what, intdiv($this->spent, 1_000_000)));

            return null;
        }

        $started = $this->clock->nanoseconds();

        try {
            $given = $call($plugin->data);
        } catch (Throwable $failure) {
            $this->spent += $this->clock->nanoseconds() - $started;
            $this->fault($plugin, $failure);

            return null;
        }

        $took = $this->clock->nanoseconds() - $started;
        $this->spent += $took;

        if ($took > self::CALL_BUDGET_MS * 1_000_000) {
            $this->fault($plugin, new AreaTooSlow($what, intdiv($took, 1_000_000)));

            return null;
        }

        $answered = true;

        return $given;
    }

    private function fault(AreaPlugin $plugin, Throwable $failure): void
    {
        try {
            $this->container->make(ListenerFaults::class)->record($plugin->name, "{$plugin->name}#area", PluginArea::class, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never stops the area's page.
        }
    }
}
