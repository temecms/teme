<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use Teme\Contracts\Content\WriteRefused;

/**
 * What one panel shows for one item, and what happens when the person uses
 * or discards one of its suggestions. Core draws it; the plugin never draws.
 *
 * @since 1.0.0
 */
interface EditorPanel
{
    /**
     * The panel's status line and the suggestions waiting for the person,
     * answered within 250 ms.
     *
     * @since 1.0.0
     */
    public function view(EditorPanelContext $context): PanelView;

    /**
     * The person used or discarded one of this panel's suggestions. Runs
     * inside core's transaction, within 2 seconds.
     *
     * @throws WriteRefused with one plain sentence; nothing is kept
     *
     * @since 1.0.0
     */
    public function settled(string $suggestionId, bool $used, EditorPanelContext $context): void;
}
