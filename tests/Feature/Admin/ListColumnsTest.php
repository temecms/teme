<?php

declare(strict_types=1);

use App\Domain\Preferences\PreferenceRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Fields\StoredFieldRows;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

const LC_PREP = '01K9F1E1D0000000000000CA01';
const LC_COURSE = '01K9F1E1D0000000000000CA02';
const LC_NOTES = '01K9F1E1D0000000000000CA03';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('shows thumbnails, Author, the nesting taxonomies and Date by default, and offers a flat taxonomy and Comments', function (): void {
    $registry = app(PreferenceRegistry::class);
    $posts = $registry->get('lists.post.columns');

    expect($posts->default)->toBe(['featured', 'author', 'taxonomy-category', 'fields', 'date'])
        ->and($posts->allows(['taxonomy-tag', 'comments']))->toBeTrue()
        ->and($registry->get('lists.page.columns')->default)->toBe(['featured', 'parent', 'author', 'template', 'fields', 'date']);
});

it('gives each page row its parent’s title, and none to a page at the top', function (): void {
    $tree = PageTree::build();

    $rows = collect($this->actingAs($tree->admin)->get(route('content.index', ['type' => 'pages']))->assertOk()->inertiaProps('rows'))->keyBy('id');

    expect($rows[$tree->team->id]['parent'])->toBe(['id' => $tree->about->id, 'title' => $tree->about->title])
        ->and($rows[$tree->about->id]['parent'])->toBeNull();
});

it('lists a type’s own fields as columns, each row with its values, and leaves a long text out', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(LC_PREP, 'prep_time', ['fieldType' => 'number']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(LC_COURSE, 'course', ['fieldType' => 'select', 'settings' => ['choices' => [['value' => 'main', 'label' => 'Main'], ['value' => 'soup', 'label' => 'Soup']]]]), 2);
    StoredFieldRows::store(StoredFieldRows::entry(LC_NOTES, 'notes', ['fieldType' => 'textarea']), 3);
    $editor = User::factory()->create()->assignRole('editor');
    $movie = lifecycleDraft($editor, 'Pepper soup', 'movie');
    DB::table('content_revisions')->where('id', $movie->working_revision_id)->update(['fields' => json_encode([LC_PREP => 45, LC_COURSE => 'soup', LC_NOTES => 'Long words.'])]);

    $response = $this->actingAs($editor)->get(route('content.index', ['type' => 'movies']))->assertOk();
    $row = collect($response->inertiaProps('rows'))->firstWhere('title', 'Pepper soup');

    expect($response->inertiaProps('listFields'))->toBe([
        ['key' => LC_PREP, 'label' => 'Prep Time', 'kind' => 'number'],
        ['key' => LC_COURSE, 'label' => 'Course', 'kind' => 'select'],
    ])
        ->and($row['fields'])->toBe([LC_PREP => '45', LC_COURSE => 'Soup'])
        ->and(app(PreferenceRegistry::class)->get('lists.movie.columns')->allows(['field-'.LC_PREP, 'fields']))->toBeTrue();
});

it('reads parents and fields in the same queries for one row as for twenty', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(LC_PREP, 'prep_time', ['fieldType' => 'number']), 1);
    $editor = User::factory()->create()->assignRole('editor');
    lifecycleDraft($editor, 'Movie 1', 'movie');

    $count = function () use ($editor): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($editor)->get(route('content.index', ['type' => 'movies']))->assertOk();

        return count(DB::getQueryLog());
    };

    $count();
    $one = $count();

    foreach (range(2, 20) as $n) {
        lifecycleDraft($editor, "Movie {$n}", 'movie');
    }

    expect($count())->toBe($one);
});
