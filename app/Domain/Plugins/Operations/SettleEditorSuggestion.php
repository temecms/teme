<?php

/**
 * Tells a plugin's editor panel that the person used or discarded one of
 * its suggestions (docs/proposals/plugin-sockets-3.md). The plugin's own
 * writes happen inside this transaction, under a 2-second budget; a
 * refusal, a fault or a call over budget rolls them all back.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Editor\EditorPanels;
use App\Domain\Plugins\Editor\PanelPlugin;
use App\Domain\Plugins\Editor\PanelReads;
use App\Domain\Plugins\Editor\PanelTooSlow;
use App\Domain\Plugins\Operations\Input\SettleEditorSuggestionInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\Suggestion;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class SettleEditorSuggestion extends BaseOperation
{
    /** The longest the plugin's settled() may take, in milliseconds. */
    public const int WRITE_BUDGET_MS = 2_000;

    public function __construct(
        private readonly EditorPanels $panels,
        private readonly ColumnClock $clock,
        private readonly ListenerFaults $faults,
        private readonly SiteLanguages $languages,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SettleEditorSuggestionInput $input */
        $this->panel($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SettleEditorSuggestionInput $input */
        if (preg_match(Suggestion::ID, $input->suggestion) !== 1) {
            throw ValidationException::withMessages(['suggestion' => __('This isn’t a suggestion’s id.')]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var SettleEditorSuggestionInput $input */
        $panel = $this->panel($input);
        $content = Content::query()->findOrFail($input->contentId);
        $data = $panel->data ?? throw ValidationException::withMessages(['suggestion' => $this->unsaved($panel)]);
        $started = $this->clock->nanoseconds();

        try {
            $data->settled($input->suggestion, $input->used, PanelReads::context($panel, $content, $input->actorId, $this->languages));
        } catch (WriteRefused $refused) {
            throw ValidationException::withMessages(['suggestion' => $refused->getMessage() !== '' ? $refused->getMessage() : $this->unsaved($panel)]);
        } catch (ValidationException|AuthorizationException $refused) {
            throw $refused;
        } catch (Throwable $failure) {
            $this->fault($panel, $failure);

            throw ValidationException::withMessages(['suggestion' => $this->unsaved($panel)]);
        }

        $took = $this->clock->nanoseconds() - $started;

        if ($took > self::WRITE_BUDGET_MS * 1_000_000) {
            $this->fault($panel, new PanelTooSlow($panel->toolId(), intdiv($took, 1_000_000)));

            throw ValidationException::withMessages(['suggestion' => $this->unsaved($panel)]);
        }

        return $input->used;
    }

    /**
     * The panel named, as this person sees it on this item.
     *
     * @throws AuthorizationException when they don't see it
     */
    private function panel(SettleEditorSuggestionInput $input): PanelPlugin
    {
        $content = Content::query()->find($input->contentId);

        return ($content === null ? null : $this->panels->find($content, $input->actorId, $input->code, $input->panel))
            ?? throw new AuthorizationException(__('Not permitted.'));
    }

    private function unsaved(PanelPlugin $panel): string
    {
        return __(':plugin couldn’t save this. Nothing was changed.', ['plugin' => $panel->plugin]);
    }

    private function fault(PanelPlugin $panel, Throwable $failure): void
    {
        try {
            $this->faults->record($panel->name, "{$panel->name}#editor-panel", EditorPanel::class, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never hides the refusal.
        }
    }
}
