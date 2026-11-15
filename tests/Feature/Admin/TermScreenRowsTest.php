<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Queries\TermScreen;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');

    // A taxonomy screen's props, as the browser receives them.
    $this->props = fn (string $segment, array $query = []): array => $this->actingAs($this->editor)
        ->get(route('terms.index', ['taxonomy' => $segment, ...$query]))
        ->assertOk()
        ->viewData('page')['props'];

    // Each row as "depth:name", in order.
    $this->shape = fn (array $props): array => array_map(fn (array $row): string => "{$row['depth']}:{$row['name']}", $props['rows']);
});

it('lays out categories as whole branches, each child under its parent and a step deeper, with what its form and confirmation need', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $roads = taxonomyTerm('category', 'Roads', $transport->id);
    taxonomyTerm('category', 'Bridges', $roads->id);
    taxonomyTerm('category', 'Ferries', $transport->id);
    taxonomyTerm('category', 'Weather');
    Term::query()->whereKey($roads->id)->update(['description' => 'Streets and highways.']);

    $this->actingAs($this->editor)
        ->get(route('terms.index', ['taxonomy' => 'categories']))
        ->assertInertia(fn (Assert $page) => $page->component('terms/index'));

    $props = ($this->props)('categories');
    $rows = collect($props['rows'])->keyBy('name');

    expect(($this->shape)($props))->toBe(['0:Transport', '1:Ferries', '1:Roads', '2:Bridges', '0:Weather'])
        ->and($rows['Transport'])->toMatchArray(['slug' => 'transport', 'path' => 'Transport', 'parentId' => null, 'parentName' => null, 'children' => 2, 'description' => ''])
        ->and($rows['Roads'])->toMatchArray(['parentId' => $transport->id, 'parentName' => 'Transport', 'children' => 1, 'description' => 'Streets and highways.'])
        ->and($rows['Bridges']['path'])->toBe('Transport › Roads › Bridges')
        ->and($props['paging']['newer'])->toBeNull()
        ->and($props['paging']['older'])->toBeNull();
});

it('never splits a branch between pages, gives a branch longer than a page a page of its own, and brings Previous back to the page Next left', function (): void {
    foreach (range(1, 45) as $n) {
        taxonomyTerm('category', sprintf('A%02d', $n));
    }

    foreach (['B' => 10, 'C' => 60, 'D' => 0] as $root => $children) {
        $parent = taxonomyTerm('category', $root);

        for ($n = 1; $n <= $children; $n++) {
            taxonomyTerm('category', sprintf('%s child %02d', $root, $n), $parent->id);
        }
    }

    $tops = fn (array $props): array => array_values(array_map(fn (array $row): string => $row['name'], array_filter($props['rows'], fn (array $row): bool => $row['depth'] === 0)));
    $one = ($this->props)('categories');
    $two = ($this->props)('categories', ['after' => $one['paging']['older']]);
    $three = ($this->props)('categories', ['after' => $two['paging']['older']]);
    $four = ($this->props)('categories', ['after' => $three['paging']['older']]);
    $back = ($this->props)('categories', ['before' => $three['paging']['newer']]);

    // 45 single rows fill page one: B's 11 rows would take it past 50.
    expect($one['rows'])->toHaveCount(45)
        ->and($one['paging']['newer'])->toBeNull()
        ->and($tops($two))->toBe(['B'])
        ->and($two['rows'])->toHaveCount(11)
        ->and($tops($three))->toBe(['C'])
        ->and($three['rows'])->toHaveCount(61)
        ->and($tops($four))->toBe(['D'])
        ->and($four['paging']['older'])->toBeNull()
        ->and(array_column($back['rows'], 'id'))->toBe(array_column($two['rows'], 'id'))
        ->and($back['paging']['newer'])->toBe($two['paging']['newer']);
});

it('opens the first page when the category a cursor names has gone', function (): void {
    foreach (range(1, 55) as $n) {
        taxonomyTerm('category', sprintf('Category %02d', $n));
    }

    $first = ($this->props)('categories');
    Term::query()->where('name', 'Category 50')->delete();

    $again = ($this->props)('categories', ['after' => $first['paging']['older']]);

    expect($again['rows'])->toHaveCount(50)
        ->and($again['rows'][0]['name'])->toBe('Category 01')
        ->and($again['paging']['newer'])->toBeNull();
});

it('lists a search\'s matches by name, flat, each with its path and parent', function (): void {
    $world = taxonomyTerm('category', 'World');
    taxonomyTerm('category', 'Africa', $world->id);
    taxonomyTerm('category', 'Asia', $world->id);

    $props = ($this->props)('categories', ['search' => 'a']);

    expect(($this->shape)($props))->toBe(['0:Africa', '0:Asia'])
        ->and(array_column($props['rows'], 'path'))->toBe(['World › Africa', 'World › Asia'])
        ->and(array_column($props['rows'], 'parentName'))->toBe(['World', 'World']);
});

it('counts each term\'s items of each listed type that uses it, leaving out the trash', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $roads = taxonomyTerm('category', 'Roads', $transport->id);
    $harbour = taxonomyTerm('tag', 'Harbour');
    typesDraft($this->editor, 'post', 'Harbour news', [$transport->id, $harbour->id]);
    listsPublish(typesDraft($this->editor, 'post', 'Ferry fares', [$transport->id, $roads->id]), $this->editor);
    $gone = typesDraft($this->editor, 'post', 'Old notice', [$transport->id, $harbour->id]);
    app(TrashContent::class)->execute(new ContentActionInput($gone->id, (string) $this->editor->id));

    $categories = ($this->props)('categories');
    $tags = ($this->props)('tags');

    expect($categories['types'][0])->toBe(['key' => 'post', 'segment' => 'posts', 'singular' => 'Post', 'plural' => 'Posts'])
        ->and(array_column($categories['rows'], 'counts', 'name'))->toBe(['Transport' => ['post' => 2], 'Roads' => ['post' => 1]])
        ->and($tags['rows'][0]['counts'])->toBe(['post' => 1]);
});

it('tabs between the taxonomies filed under one type, each with how many terms it has, and sends a flat one no tree', function (): void {
    taxonomyTerm('category', 'Transport');
    taxonomyTerm('tag', 'Harbour');
    taxonomyTerm('tag', 'Ferries');

    $tags = ($this->props)('tags');

    expect($tags['tabs'][0])->toBe(['segment' => 'categories', 'label' => 'Categories', 'count' => 1])
        ->and($tags['tabs'][1])->toBe(['segment' => 'tags', 'label' => 'Tags', 'count' => 2])
        ->and($tags['declared'])->toBe(['key' => 'tag', 'singular' => 'Tag', 'plural' => 'Tags', 'hierarchical' => false])
        ->and($tags['tree'])->toBe([])
        ->and(($this->props)('categories')['tree'])->toHaveCount(1);
});

it('tabs only between the taxonomies someone may manage under one type, and lets one no listed type uses stand alone', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('recipe', 'Recipe', 'Recipes', 'recipes', taxonomies: ['course', 'cuisine']));
    app(TaxonomyRegistry::class)->register(new Taxonomy('course', 'Course', 'Courses', 'courses', hierarchical: true));
    app(TaxonomyRegistry::class)->register(new Taxonomy('cuisine', 'Cuisine', 'Cuisines', 'cuisines', ability: 'users.manage'));
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics'));
    $admin = User::factory()->create()->assignRole('administrator');
    $screen = app(TermScreen::class);

    expect(array_column($screen->tabs('course', $this->editor), 'segment'))->toBe(['courses'])
        ->and(array_column($screen->tabs('course', $admin), 'segment'))->toBe(['courses', 'cuisines'])
        ->and(array_column($screen->tabs('topic', $admin), 'segment'))->toBe(['topics'])
        ->and(array_column($screen->countedTypes('course'), 'key'))->toBe(['recipe'])
        ->and($screen->countedTypes('topic'))->toBe([]);
});
