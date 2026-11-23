<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Themes\ActiveTheme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    Http::fake();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('runs no widgets step and no menu places step, in a full run or a catch-up, and says once that the menus came unplaced', function (): void {
    $keys = fn (ImportRunKind $kind): array => array_map(fn (ImportPhase $phase): string => $phase->key(), array_values(array_filter(app(ImportPhases::class)->all(), fn (ImportPhase $phase): bool => $phase->runsIn($kind))));

    foreach (ImportRunKind::cases() as $kind) {
        expect($keys($kind))->not->toContain('widgets')->not->toContain('menu-places')->toContain('menus')->toContain('unplaced-menus');
    }
});

it('carries a run that was at a step this release retired on with the next step, never from the start', function (): void {
    $phases = app(ImportPhases::class);

    expect($phases->resume('menu-places', ImportRunKind::Full)?->key())->toBe('unplaced-menus')
        ->and($phases->resume('widgets', ImportRunKind::Full)?->key())->toBe('redirects')
        ->and($phases->after('widgets', ImportRunKind::Full)?->key())->toBe('redirects')
        ->and($phases->resume('menus', ImportRunKind::Full)?->key())->toBe('menus')
        ->and($phases->resume('never-a-step', ImportRunKind::Full)?->key())->toBe($phases->first(ImportRunKind::Full)?->key());
});

it('asks a file’s plan neither where menus go nor which template a page uses, ignores both when sent, and brings the menus unplaced', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $analysis = app(ImportAnalysis::class)->read((string) $file->source_id);
    $form = app(ImportChoices::class)->fileForm($analysis, $file->id, (string) $this->admin->id, ['menu_locations' => ['menu-3' => 'primary'], 'templates' => ['template-full-width.php' => 'full-width']]);
    $sent = [
        'menu_locations' => ['menu-3' => 'primary'], 'templates' => ['template-full-width.php' => 'full-width'], 'owner' => (string) $this->admin->id, 'pause' => '1000',
        'authors' => ['ada' => 'new:editor', 'grace' => 'new:author', 'harbour' => 'to:'.$this->admin->id, 'seo' => 'new:author', 'reader' => 'new:contributor'],
        'media' => 'links', 'category_base' => 'category', 'tag_base' => 'tag', 'types' => [], 'drafts' => '1', 'comments' => '1',
    ];
    $choices = app(ImportChoices::class)->validatedFile($sent, $analysis, $file->id)['choices'];

    expect($form)->not->toHaveKeys(['menuLocations', 'slots', 'templates', 'themeTemplates'])
        ->and($choices)->not->toHaveKeys(['menu_locations', 'templates']);

    // A run whose stored choices still hold both, as an earlier release kept them, finishes.
    $run = FileImports::run($file, [...$choices, 'menu_locations' => ['menu-3' => 'primary'], 'templates' => ['template-full-width.php' => 'full-width']]);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(array_filter(app(ThemeSlotSettings::class)->bound(app(ActiveTheme::class)->get())))->toBe([])
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'menus.unplaced')->pluck('message')->all())->toBe(['2 menus came across; place them under Content › Navigation.'])
        ->and(DB::table('import_messages')->where('run_id', $run->id)->whereIn('code', ['post.template-default', 'menu-places.none-chosen'])->count())->toBe(0)
        ->and(DB::table('contents')->whereNotNull(DB::raw("fields->>'template'"))->count())->toBe(0);
});
