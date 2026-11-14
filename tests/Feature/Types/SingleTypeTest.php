<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ctCreate($this->admin, ['key' => 'about', 'singular' => 'About page', 'plural' => 'About pages', 'segment' => 'about', 'icon' => 'info', 'single' => true, 'archive' => false, 'taxonomies' => [], 'supports' => ['seo'], 'comments' => null]);
});

it('makes the one About page, and refuses a second whether the first is in the list or the trash', function (): void {
    $first = lifecycleDraft($this->admin, 'About us', 'about');

    expect(fn () => lifecycleDraft($this->admin, 'Again', 'about'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['type'])->toContain('There can be only one About page, and it already exists.');
    });

    pageTrash($first, $this->admin);

    expect(fn () => lifecycleDraft($this->admin, 'Again', 'about'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['type'])->toContain('There can be only one About page, and it’s in the trash: restore it to change it.');
    });

    expect(Content::withTrashed()->where('type', 'about')->count())->toBe(1);
});

it('opens the About page from its list straight in the editor, for whoever may change it', function (): void {
    $item = lifecycleDraft($this->admin, 'About us', 'about');

    $this->actingAs($this->admin)->get('/admin/about')->assertRedirect(route('content.edit', ['content' => $item->id]));

    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->get('/admin/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('content/index')->where('canAdd', false));
});

it('keeps the list for an address that names a tab, and offers Add new only while there is no About page', function (): void {
    $this->actingAs($this->admin)->get('/admin/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('content/index')->where('canAdd', true));

    $item = lifecycleDraft($this->admin, 'About us', 'about');

    $this->actingAs($this->admin)->get('/admin/about?tab=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canAdd', false));

    pageTrash($item, $this->admin);

    $this->actingAs($this->admin)->get('/admin/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canAdd', false));
    $this->actingAs($this->admin)->get('/admin/posts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canAdd', true));
});

it('leaves New out of the palette for a type with one item only', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->where('contentTypes', fn ($types): bool => collect($types)->firstWhere('key', 'about')['canCreate'] === false
            && collect($types)->firstWhere('key', 'post')['canCreate'] === true));
});

it('carries one item only through teme-types.json, and a type a file makes one item only is', function (): void {
    expect(TypesFile::export()['types'][0])->toMatchArray(['key' => 'about', 'single' => true]);

    scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput(ctFile([ctDefinition(['single' => true])->toArray()]))));

    expect(app(ContentTypeRegistry::class)->get('film')->single)->toBeTrue();
});
