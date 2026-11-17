<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linkfinder;

use Closure;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\HasEditorPanels;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that suggests links from the article being edited, in a
 * panel of the editor, and finds them in a job. The tests set what its
 * panel answers and what happens when a suggestion is used or discarded.
 */
final class Linkfinder implements HasEditorPanels, Plugin, PluginHasJobs
{
    /** @var (Closure(EditorPanelContext): mixed)|null what the panel's view answers */
    public static ?Closure $view = null;

    /** @var (Closure(string, bool, EditorPanelContext): void)|null what settling a suggestion does */
    public static ?Closure $settled = null;

    /** @var array<string, mixed>|null the panels it hands over, when a test changes them */
    public static ?array $panels = null;

    /** @var list<EditorPanelContext> the contexts the panel was asked with */
    public static array $asked = [];

    /** @var list<array{0: string, 1: bool}> the suggestions settled, and whether each was used */
    public static array $settles = [];

    public static function reset(): void
    {
        self::$view = null;
        self::$settled = null;
        self::$panels = null;
        self::$asked = [];
        self::$settles = [];
    }

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return ['find-links' => new FindLinksHandler];
    }

    /**
     * @return array<string, EditorPanel>
     */
    public function editorPanels(): array
    {
        /** @var array<string, EditorPanel> */
        return self::$panels ?? ['links' => new LinksPanel];
    }
}
