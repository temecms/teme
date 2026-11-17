<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

/**
 * A plugin whose manifest declares editor panels: a pane in the content
 * editor that core draws from the data each panel returns.
 *
 * @since 0.9.0
 */
interface HasEditorPanels
{
    /**
     * The plugin's panels, by declared panel id.
     *
     * @return array<string, EditorPanel>
     *
     * @since 0.9.0
     */
    public function editorPanels(): array;
}
