<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');

    // The link box's answer to what was typed, as the editor's own request gets it.
    $this->search = fn (string $query = ''): array => $this->actingAs($this->editor)
        ->getJson('/admin/content/link-choices?q='.rawurlencode($query))
        ->assertOk()
        ->json();
});

it('offers only articles, the most recent first, until something is typed', function (): void {
    lifecycleDraft($this->editor, 'Older notes');
    $newest = lifecycleDraft($this->editor, 'Newest notes');
    typesTerm($this->editor, 'category', 'Harbour');

    $groups = ($this->search)();

    expect(array_column($groups, 'key'))->toBe(['content'])
        ->and($groups[0]['label'])->toBe('Items')
        ->and($groups[0]['choices'][0])->toBe(['href' => "teme:content/{$newest->id}", 'title' => 'Newest notes', 'note' => 'not published yet']);
});

it('offers the articles, each taxonomy’s terms, the authors and the files that match, each as the address a link stores', function (): void {
    $festival = addrPublished($this->editor, 'Harbour festival');
    $category = typesTerm($this->editor, 'category', 'Harbour');
    $tag = typesTerm($this->editor, 'tag', 'Harbour walks');
    $ada = lkAuthor('Ada Harbour');
    $map = mediaUpload($this->editor, mediaPdf(), 'Harbour map.pdf');

    $groups = ($this->search)('harbour');

    expect(array_column($groups, 'key'))->toBe(['content', 'taxonomy:category', 'taxonomy:tag', 'authors', 'files'])
        ->and(array_column($groups, 'label'))->toBe(['Items', 'Categories', 'Tags', 'Authors', 'Files'])
        ->and($groups[0]['choices'])->toBe([['href' => "teme:content/{$festival->id}", 'title' => 'Harbour festival', 'note' => null]])
        ->and($groups[1]['choices'])->toBe([['href' => "teme:term/{$category->id}", 'title' => 'Harbour', 'note' => null]])
        ->and($groups[2]['choices'])->toBe([['href' => "teme:term/{$tag->id}", 'title' => 'Harbour walks', 'note' => null]])
        ->and($groups[3]['choices'])->toBe([['href' => "teme:user/{$ada->id}", 'title' => 'Ada Harbour', 'note' => null]])
        ->and($groups[4]['choices'])->toBe([['href' => "teme:media/{$map->id}", 'title' => $map->title, 'note' => 'PDF']]);
});

it('leaves out what a link couldn’t lead to: a file in the trash, a person who has published nothing, a taxonomy with no pages', function (): void {
    typesFresh();
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics', public: false));
    taxonomyTerm('topic', 'Harbour matters');
    $quiet = User::factory()->create(['name' => 'Ben Harbour'])->assignRole('author');
    app(PathProjection::class)->syncUser($quiet);
    $gone = mediaUpload($this->editor, mediaPdf(), 'Harbour map.pdf');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->editor->id));

    expect(($this->search)('harbour'))->toBe([]);
});

it('refuses the search to someone who may not write', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/content/link-choices?q=harbour')
        ->assertForbidden();
});

it('searches with the link box for everything a link can lead to by ID, and stores the address it is given', function (): void {
    expect(uiComponent('components/editor/link-popover.tsx'))
        ->toContain('useLinkChoices(query, open)')
        ->toContain('apply(choice.href)')
        ->toContain('Link to something on this site')
        ->toContain('placeholder="Search by title or name"')
        ->not->toContain('useLinkTargets')
        ->and(uiComponent('components/editor/use-link-choices.ts'))->toContain('LinkChoicesController({ query: { q: query } })');
});
