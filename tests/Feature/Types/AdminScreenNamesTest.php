<?php

declare(strict_types=1);

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->form = [
        'key' => 'plugin_entry', 'singular' => 'Plugin', 'plural' => 'plugins', 'segment' => 'plugin-entries', 'icon' => 'plug',
        'single' => '0', 'public' => '1', 'archive' => '1', 'searchable' => '1',
        'taxonomies' => [], 'supports' => [], 'comments' => '', 'pattern' => '',
    ];
});

it('refuses a type whose plural is an admin screen’s name, in any case, naming the screen and a name to take instead', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', $this->form)
        ->assertSessionHasErrors(['plural' => 'The admin menu already has Plugins. Give the type another plural name, such as “Plugin directory”.']);

    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'plural' => 'Plugin directory'])->assertSessionHasNoErrors();

    expect(app(ContentTypeRegistry::class)->get('plugin_entry')->plural)->toBe('Plugin directory');
});

it('refuses a type named as another type’s list, and a taxonomy named as a screen', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'singular' => 'Article', 'plural' => 'POSTS'])
        ->assertSessionHasErrors(['plural' => 'The admin menu already has Posts. Give the type another plural name, such as “Article directory”.']);

    $this->actingAs($this->admin)->post('/admin/taxonomies', [
        'singular' => 'Topic', 'plural' => 'Comments', 'segment' => 'topics', 'icon' => 'tag',
        'hierarchical' => '0', 'public' => '1', 'base' => 'topic', 'types' => ['post'],
    ])->assertSessionHasErrors(['plural' => 'The admin menu already has Comments. Give the taxonomy another plural name, such as “Topic directory”.']);

    expect(DB::table(StoredTypes::TABLE)->count())->toBe(0)
        ->and(DB::table('taxonomy_definitions')->count())->toBe(0);
});

it('refuses an imported type named as an admin screen', function (): void {
    $file = ['format' => 'teme-types', 'version' => 1, 'types' => [[
        'key' => 'setting', 'singular' => 'Setting', 'plural' => 'Settings', 'segment' => 'site-settings', 'icon' => 'file',
        'public' => true, 'archive' => false, 'single' => false, 'searchable' => true, 'taxonomies' => [], 'supports' => [],
    ]]];

    expect(fn () => scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput($file))))
        ->toThrow(ValidationException::class, 'The admin menu already has Settings. Give the type another plural name, such as “Setting directory”.');
    expect(DB::table(StoredTypes::TABLE)->count())->toBe(0);
});

it('refuses a type or taxonomy from code named as an admin screen', function (): void {
    expect(fn () => app(ContentTypeRegistry::class)->register(new ContentType('tool', 'Tool', 'Tools', 'tool-items')))
        ->toThrow(InvalidArgumentException::class, 'Content type [tool] can\'t be called [Tools]: The admin menu already has Tools.')
        ->and(fn () => app(TaxonomyRegistry::class)->register(new Taxonomy('shelf', 'Shelf', 'Media', 'shelves')))
        ->toThrow(InvalidArgumentException::class, 'Taxonomy [shelf] can\'t be called [Media]: The admin menu already has Media.');
});

it('keeps a type stored with such a name working, and asks in Settings › Types to rename it', function (): void {
    DB::table(StoredTypes::TABLE)->insert(['key' => 'theme_entry', 'definition' => json_encode(['key' => 'theme_entry', 'singular' => 'Theme', 'plural' => 'Themes', 'segment' => 'theme-entries', 'icon' => 'brush', 'public' => true]), 'created_at' => now(), 'updated_at' => now()]);
    app(StoredTypes::class)->remember();
    app(ContentTypeRegistry::class)->refreshStored();
    $warning = 'The admin menu already has Themes. Give the type another plural name, such as “Theme directory”.';

    expect(app(ContentTypeRegistry::class)->has('theme_entry'))->toBeTrue();
    $this->actingAs($this->admin)->get('/admin/theme-entries')->assertOk();
    $this->actingAs($this->admin)->get('/admin/types')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('types.3.key', 'theme_entry')->where('types.3.nameClash', $warning)->where('types.0.nameClash', null));
    $this->actingAs($this->admin)->get('/admin/types/theme_entry')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nameClash', $warning));
});
