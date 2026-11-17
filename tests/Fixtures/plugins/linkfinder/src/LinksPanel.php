<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linkfinder;

use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelView;

/**
 * The link suggestions panel: what the test set, else a quiet status line.
 */
final class LinksPanel implements EditorPanel
{
    public function view(EditorPanelContext $context): PanelView
    {
        Linkfinder::$asked[] = $context;

        if (Linkfinder::$view !== null) {
            /** @var PanelView */
            return (Linkfinder::$view)($context);
        }

        return new PanelView(status: 'No links suggested yet.');
    }

    public function settled(string $suggestionId, bool $used, EditorPanelContext $context): void
    {
        Linkfinder::$settles[] = [$suggestionId, $used];

        if (Linkfinder::$settled !== null) {
            (Linkfinder::$settled)($suggestionId, $used, $context);
        }
    }
}
