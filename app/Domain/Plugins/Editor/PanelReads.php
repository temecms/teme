<?php

/**
 * Reads a plugin's editor panel for one item (docs/proposals/plugin-sockets-3.md,
 * B4): view() under 250 ms, each suggestion checked against the item and
 * dropped with a recorded fault when it fails, every value plain text. A
 * view that throws, runs over or answers wrongly is recorded under the
 * plugin and the panel says it couldn't be shown; its jobs still show.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\Plugins\PluginAiSpend;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Columns\CellText;
use App\Domain\Plugins\Columns\ColumnClock;
use DateTimeInterface;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelPreview;
use Teme\Contracts\Editor\PanelRow;
use Teme\Contracts\Editor\PanelView;
use Throwable;

final readonly class PanelReads
{
    public const int CALL_BUDGET_MS = 250;

    public function __construct(
        private ColumnClock $clock,
        private SuggestionCheck $check,
        private ListenerFaults $faults,
        private SiteLanguages $languages,
        private EditorPanels $panels,
        private PluginAiTasks $aiTasks,
        private PluginAiSection $aiSection,
        private PluginAiSpend $aiSpend,
    ) {}

    /**
     * What the named panel shows this person on this item, or null when
     * they don't see it there; the plugin is never asked then.
     *
     * @return array{status: string|null, tone: string, suggestions: list<array<string, mixed>>, failed: string|null, jobs: list<array<string, mixed>>, busy: bool, ai: string|null, rowsHeading: string|null, rows: list<array<string, mixed>>, preview: array<string, mixed>|null, saved: array<string, mixed>, savedAt: string|null}|null
     */
    public function forItem(string $contentId, string $code, string $panel, string $personId): ?array
    {
        $content = Content::query()->find($contentId);
        $found = $content === null ? null : $this->panels->find($content, $personId, $code, $panel);

        return $content === null || $found === null ? null : $this->read($found, $content, $personId);
    }

    /**
     * What the panel shows this person on this item: its status line and
     * tone, its checked suggestions, the line saying it couldn't be shown
     * (or null), its jobs, whether the page should ask again, and whether
     * the plugin's own AI is switched off (`off`) or its month's budget is
     * spent (`budget`), null when neither or the plugin uses no AI.
     *
     * The rows, preview and saved values are plain values; a row's date is
     * sent as an ISO 8601 string for the page to format.
     *
     * @return array{status: string|null, tone: string, suggestions: list<array<string, mixed>>, failed: string|null, jobs: list<array<string, mixed>>, busy: bool, ai: string|null, rowsHeading: string|null, rows: list<array<string, mixed>>, preview: array<string, mixed>|null, saved: array<string, mixed>, savedAt: string|null}
     */
    public function read(PanelPlugin $panel, Content $content, string $personId): array
    {
        $jobs = PanelJobs::of($panel->code, $panel->panel->id, $content->id);
        $view = $this->view($panel, self::context($panel, $content, $personId, $this->languages));

        if ($view === null) {
            return ['status' => null, 'tone' => 'neutral', 'suggestions' => [], 'failed' => __('This panel couldn’t be shown this time.'), 'jobs' => $jobs, 'busy' => PanelJobs::busy($jobs), 'ai' => $this->ai($panel), ...$this->details(null)];
        }

        $suggestions = [];

        foreach ($view->suggestions as $suggestion) {
            try {
                $checked = $this->check->check($suggestion, $content);
            } catch (Throwable $refused) {
                $this->fault($panel, $refused);

                continue;
            }

            $suggestions[] = [
                ...$checked,
                'label' => (string) CellText::clean($checked['label'], 120),
                'detail' => (string) CellText::clean($checked['detail'], 500),
            ];
        }

        return [
            'status' => $view->status === null ? null : CellText::clean($view->status, PanelView::MOST_STATUS),
            'tone' => $view->tone,
            'suggestions' => $suggestions,
            'failed' => null,
            'jobs' => $jobs,
            'busy' => PanelJobs::busy($jobs),
            'ai' => $this->ai($panel),
            ...$this->details($view),
        ];
    }

    /**
     * The rows, preview and saved values of a view, every string cleaned to
     * plain text and capped; none when the view has none of them.
     *
     * @return array{rowsHeading: string|null, rows: list<array<string, mixed>>, preview: array<string, mixed>|null, saved: array<string, mixed>, savedAt: string|null}
     */
    private function details(?PanelView $view): array
    {
        if ($view === null) {
            return ['rowsHeading' => null, 'rows' => [], 'preview' => null, 'saved' => [], 'savedAt' => null];
        }

        return [
            'rowsHeading' => $view->rows === [] ? null : (string) CellText::clean($view->rowsHeading ?? __('Details'), PanelRow::MOST_LABEL),
            'rows' => array_map(fn (PanelRow $row): array => [
                'label' => (string) CellText::clean($row->label, PanelRow::MOST_LABEL),
                'value' => $row->value instanceof DateTimeInterface
                    ? ['kind' => 'date', 'iso' => $row->value->format(DATE_ATOM)]
                    : (string) CellText::clean($row->value, PanelRow::MOST_VALUE),
                'tone' => $row->tone,
            ], $view->rows),
            'preview' => $view->preview === null ? null : $this->preview($view->preview),
            'saved' => array_map(fn (mixed $value): mixed => is_string($value) ? CellText::clean($value, PanelView::MOST_SAVED) : $value, $view->saved),
            'savedAt' => $view->savedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * The preview card: the plugin's words, the picture from the library by
     * its ID, and the site's own host. A picture that isn't in the library is
     * left out.
     *
     * @return array{title: string, description: string, picture: string|null, host: string|null}
     */
    private function preview(PanelPreview $preview): array
    {
        $media = $preview->mediaId === null ? null : Media::query()->find($preview->mediaId);
        $host = parse_url(url('/'), PHP_URL_HOST);

        return [
            'title' => (string) CellText::clean($preview->title, PanelPreview::MOST_TITLE),
            'description' => (string) CellText::clean($preview->description, PanelPreview::MOST_DESCRIPTION),
            'picture' => $media === null ? null : MediaUrls::largest($media->variants, (string) $media->id),
            'host' => is_string($host) ? $host : null,
        ];
    }

    /**
     * Whether the plugin's own AI is switched off in Settings › AI (`off`),
     * or its budget for this month is spent (`budget`); null otherwise.
     */
    private function ai(PanelPlugin $panel): ?string
    {
        try {
            $enabled = $this->aiTasks->enabled();
            $code = $panel->code;

            if (! isset($enabled[$code])) {
                return null;
            }

            if (! $this->aiSection->on($code)) {
                return 'off';
            }

            $budget = $this->aiSection->budgetMicrounits($code);

            return $budget !== null && $this->aiSpend->month($code)->costMicrounits >= $budget ? 'budget' : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What a panel is told about the item and the person.
     */
    public static function context(PanelPlugin $panel, Content $content, string $personId, SiteLanguages $languages): EditorPanelContext
    {
        $area = AreaContexts::for($panel->code, $personId);

        return new EditorPanelContext(
            itemId: $content->id,
            revisionId: (string) $content->working_revision_id,
            type: $content->type,
            language: $content->language ?? $languages->defaultTag(),
            personId: $personId,
            locale: $area->locale,
            permissions: $area->permissions,
        );
    }

    /**
     * The plugin's view, or null when it couldn't be had in time.
     */
    private function view(PanelPlugin $panel, EditorPanelContext $context): ?PanelView
    {
        if (! $panel->data instanceof EditorPanel) {
            $this->fault($panel, new PanelAnswerRefused("{$panel->toolId()} has no EditorPanel while it runs."));

            return null;
        }

        $started = $this->clock->nanoseconds();

        try {
            $view = $panel->data->view($context);
        } catch (Throwable $failure) {
            $this->fault($panel, $failure);

            return null;
        }

        $took = $this->clock->nanoseconds() - $started;

        if ($took > self::CALL_BUDGET_MS * 1_000_000) {
            $this->fault($panel, new PanelTooSlow($panel->toolId(), intdiv($took, 1_000_000)));

            return null;
        }

        return $view;
    }

    private function fault(PanelPlugin $panel, Throwable $failure): void
    {
        try {
            $this->faults->record($panel->name, "{$panel->name}#editor-panel", EditorPanel::class, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never stops the editor.
        }
    }
}
