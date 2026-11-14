<?php

declare(strict_types=1);

use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Operations\Input\ReplaceMediaFileInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\RecordMediaVariants;
use App\Domain\Media\Operations\ReplaceMediaFile;
use App\Domain\Media\Operations\RestoreMedia;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Domain\Menus\Operations\Input\RenameNavigationInput;
use App\Domain\Menus\Operations\RenameNavigation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('purges the pages that draw an image when its details change, and nothing when they don\'t', function (): void {
    $heron = pageImage($this->editor, 1200, 800);
    cacheShown(['/draws-it/' => ['media:'.$heron->id], '/draws-another/' => ['media:OTHER']]);
    $describe = fn (string $alt) => app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($heron->id, (string) $this->editor->id, $heron->title, $alt, $heron->caption, $heron->credit, $heron->focal_x, $heron->focal_y));

    $describe((string) $heron->alt);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    cacheRolledBack(fn () => $describe('A heron at dawn'));

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    $describe('A heron at dawn');

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});

it('purges the pages that draw an image when its file is replaced', function (): void {
    $heron = pageImage($this->editor, 1200, 800);
    cacheShown(['/draws-it/' => ['media:'.$heron->id], '/draws-another/' => ['media:OTHER']]);
    $replace = fn () => app(ReplaceMediaFile::class)->execute(new ReplaceMediaFileInput($heron->id, mediaJpeg(1000, 700), 'Heron.jpg', (string) $this->editor->id));

    cacheRolledBack($replace);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    $replace();

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});

it('purges the pages that draw an image when its sizes are recorded', function (): void {
    $heron = pageImage($this->editor, 1200, 800)->refresh();
    cacheShown(['/draws-it/' => ['media:'.$heron->id], '/draws-another/' => ['media:OTHER']]);
    $record = fn () => app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($heron->id, $heron->file_version, MediaVariants::SET, $heron->variants, null, (string) $this->editor->id));

    cacheRolledBack($record);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    $record();

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});

it('purges the pages that draw an image when it goes to the trash', function (): void {
    $heron = pageImage($this->editor, 1200, 800);
    cacheShown(['/draws-it/' => ['media:'.$heron->id], '/draws-another/' => ['media:OTHER']]);
    $trash = fn () => app(TrashMedia::class)->execute(new MediaActionInput($heron->id, (string) $this->editor->id));

    cacheRolledBack($trash);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    $trash();

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});

it('purges them again when it comes back from the trash', function (): void {
    $heron = pageImage($this->editor, 1200, 800);
    app(TrashMedia::class)->execute(new MediaActionInput($heron->id, (string) $this->editor->id));
    cacheShown(['/draws-it/' => ['media:'.$heron->id], '/draws-another/' => ['media:OTHER']]);
    $restore = fn () => app(RestoreMedia::class)->execute(new MediaActionInput($heron->id, (string) $this->editor->id));

    cacheRolledBack($restore);

    expect(cacheKept('/draws-it/', '/draws-another/'))->toHaveCount(2);

    $restore();

    expect(cacheKept('/draws-it/', '/draws-another/'))->toBe(['/draws-another/']);
});

it('purges every page that draws a menu when its items change', function (): void {
    $nav = navCreate($this->editor);
    cacheShown(['/draws-it/' => ['menu:'.$nav->handle], '/draws-another/' => ['menu:footer-links'], '/the-design/' => ['theme']]);
    $save = fn () => navSave($this->editor, $nav, [['kind' => 'home']]);

    cacheRolledBack($save);

    expect(cacheKept('/draws-it/', '/draws-another/', '/the-design/'))->toHaveCount(3);

    $save();

    expect(cacheKept('/draws-it/', '/draws-another/', '/the-design/'))->toBe(['/draws-another/', '/the-design/']);
});

it('purges the pages that drew a menu, and every page of the design when it was in a place, when it is deleted', function (): void {
    $nav = navCreate($this->editor);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));
    cacheShown(['/draws-it/' => ['menu:'.$nav->handle], '/the-design/' => ['theme'], '/shows-an-article/' => ['content:unrelated']]);
    $delete = fn () => app(DeleteNavigation::class)->execute(new DeleteNavigationInput($nav->id, (string) $this->editor->id));

    cacheRolledBack($delete);

    expect(cacheKept('/draws-it/', '/the-design/', '/shows-an-article/'))->toHaveCount(3);

    $delete();

    expect(cacheKept('/draws-it/', '/the-design/', '/shows-an-article/'))->toBe(['/shows-an-article/']);
});

it('purges nothing when a menu is renamed, since no page draws its name', function (): void {
    $nav = navCreate($this->editor);
    cacheShown(['/draws-it/' => ['menu:'.$nav->handle]]);

    app(RenameNavigation::class)->execute(new RenameNavigationInput($nav->id, 'Header links', (string) $this->editor->id));

    expect(cacheKept('/draws-it/'))->toBe(['/draws-it/']);
});

it('purges every page of the design when a menu is placed', function (): void {
    $nav = navCreate($this->editor);
    cacheShown(['/the-design/' => ['theme'], '/shows-an-article/' => ['content:unrelated']]);
    $bind = fn () => app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    cacheRolledBack($bind);

    expect(cacheKept('/the-design/', '/shows-an-article/'))->toHaveCount(2);

    $bind();

    expect(cacheKept('/the-design/', '/shows-an-article/'))->toBe(['/shows-an-article/']);
});

it('purges every page of the design when the design is published', function (): void {
    cacheShown(['/the-design/' => ['theme'], '/shows-an-article/' => ['content:unrelated']]);

    cacheRolledBack(fn () => designPublish(['design' => ['accent' => '#1d4ed8']]));

    expect(cacheKept('/the-design/', '/shows-an-article/'))->toHaveCount(2);

    designPublish(['design' => ['accent' => '#1d4ed8']]);

    expect(cacheKept('/the-design/', '/shows-an-article/'))->toBe(['/shows-an-article/']);
});

it('purges every page when the addresses change', function (): void {
    cacheShown(['/shows-an-article/' => ['content:unrelated'], '/lists-posts/' => ['list:post']]);

    cacheRolledBack(fn () => addrChange($this->admin, ['authorBase' => 'writers']));

    expect(cacheKept('/shows-an-article/', '/lists-posts/'))->toHaveCount(2);

    addrChange($this->admin, ['authorBase' => 'writers']);

    expect(cacheKept('/shows-an-article/', '/lists-posts/'))->toBe([]);
});
