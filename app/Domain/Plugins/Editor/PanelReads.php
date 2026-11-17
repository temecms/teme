<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Columns\CellText;
use App\Domain\Plugins\Columns\ColumnClock;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelView;
use Throwable;

/**
 * Reads a plugin's editor panel for one item (docs/proposals/plugin-sockets-3.md,
 * B4): view() under 250 ms, each suggestion checked against the item and
 * dropped with a recorded fault when it fails, every value plain text. A
 * view that throws, runs over or answers wrongly is recorded under the
 * plugin and the panel says it couldn't be shown; its jobs still show.
 */
final readonly class PanelReads
{
    public const int CALL_BUDGET_MS = 250;

    public function __construct(
        private ColumnClock $clock,
        private SuggestionCheck $check,
        private ListenerFaults $faults,
        private SiteLanguages $languages,
        private EditorPanels $panels,
    ) {}

    /**
     * What the named panel shows this person on this item, or null when
     * they don't see it there; the plugin is never asked then.
     *
     * @return array{status: string|null, tone: string, suggestions: list<array<string, mixed>>, failed: string|null, jobs: list<array<string, mixed>>, busy: bool}|null
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
     * (or null), its jobs, and whether the page should ask again.
     *
     * @return array{status: string|null, tone: string, suggestions: list<array<string, mixed>>, failed: string|null, jobs: list<array<string, mixed>>, busy: bool}
     */
    public function read(PanelPlugin $panel, Content $content, string $personId): array
    {
        $jobs = PanelJobs::of($panel->code, $panel->panel->id, $content->id);
        $view = $this->view($panel, self::context($panel, $content, $personId, $this->languages));

        if ($view === null) {
            return ['status' => null, 'tone' => 'neutral', 'suggestions' => [], 'failed' => __('This panel couldn’t be shown this time.'), 'jobs' => $jobs, 'busy' => PanelJobs::busy($jobs)];
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
        ];
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
