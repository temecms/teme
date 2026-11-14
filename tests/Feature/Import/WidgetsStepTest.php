<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\Menus\WidgetsStep;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\MenuImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * The widgets step alone, run over the site report's widgets section with these keys replaced.
 *
 * @param  array<string, mixed>  $widgets
 */
function wsRun(object $test, array $widgets): ImportRun
{
    $test->companion->items('analyze', tiReport(['widgets' => $widgets]));
    importPhaseAlone(app(WidgetsStep::class));

    return importRun($test->source);
}

it('says what the widgets were, by sidebar and by kind, leaving the inactive ones out', function (): void {
    $run = wsRun($this, [
        'sidebars' => ['wp_inactive_widgets' => 3, 'sidebar-1' => 4, 'footer-1' => 2],
        'widget_types' => ['text' => 3, 'recent-posts' => 2, 'custom_html' => 1, 'inactive:text' => 3],
    ]);

    expect(MenuImports::runMessages($run, 'widgets.'))->toBe([
        ['widgets.reported', '6 widgets weren’t imported, since Teme’s theme lays out regions of its own: set those up instead. By sidebar: sidebar-1 (4) and footer-1 (2). By kind: text (3), recent-posts (2) and custom_html (1).'],
    ]);
});

it('says it for one widget, without kinds the report doesn’t count, and not at all when no sidebar holds one', function (array $widgets, array $said): void {
    expect(MenuImports::runMessages(wsRun($this, $widgets), 'widgets.'))->toBe($said);
})->with([
    'one widget' => [
        ['sidebars' => ['wp_inactive_widgets' => 0, 'sidebar-1' => 1, 'footer-1' => 0], 'widget_types' => ['search' => 1]],
        [['widgets.reported', '1 widget wasn’t imported, since Teme’s theme lays out regions of its own: set those up instead. By sidebar: sidebar-1 (1). By kind: search (1).']],
    ],
    'kinds not counted' => [
        ['sidebars' => ['wp_inactive_widgets' => 0, 'sidebar-1' => 2, 'footer-1' => 0]],
        [['widgets.reported', '2 widgets weren’t imported, since Teme’s theme lays out regions of its own: set those up instead. By sidebar: sidebar-1 (2).']],
    ],
    'none in a sidebar' => [['sidebars' => ['wp_inactive_widgets' => 3, 'sidebar-1' => 0, 'footer-1' => 0]], []],
]);

it('says nothing when the site has no report', function (): void {
    $this->companion->items('analyze', ['api' => 1, 'report' => null, 'message' => 'No report yet. Run Tools › Teme site report first.']);
    importPhaseAlone(app(WidgetsStep::class));

    expect(MenuImports::runMessages(importRun($this->source), 'widgets.'))->toBe([]);
});
