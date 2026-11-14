<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\Breadcrumbs;
use App\Domain\Site\ViewModels\Crumb;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->news = sdCategory('News');
    $this->local = sdCategory('Local', $this->news);
    $this->sport = sdCategory('Sport');
});

it('gives the editor what the type says its items are, and the article types they may choose (I-709)', function (string $type, ?string $mainEntity): void {
    $item = lifecycleDraft($this->editor, 'Harbour news', $type);

    $this->actingAs($this->editor)->get("/admin/content/{$item->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('seo.schema.pageType', 'WebPage')
        ->where('seo.schema.mainEntity', $mainEntity)
        ->where('seo.schema.mainEntities', ['Article', 'NewsArticle', 'BlogPosting']));
})->with([
    'a post' => ['post', 'BlogPosting'],
    'a page' => ['page', null],
]);

it('says whether the address follows the primary category, and whether the theme draws a trail (I-869, I-870)', function (bool $set): void {
    if ($set) {
        addrChange(User::factory()->create()->assignRole('administrator'), ['patterns' => ['post' => '/{category}/{slug}/']]);
        sdTrailTheme();
    }

    $post = lifecycleDraft($this->editor, 'Harbour news');

    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('seo.addressFollowsCategory', $set)
        ->where('seo.trail', $set));
})->with([
    'as installed' => [false],
    'with {category} in the pattern and a theme that draws a trail' => [true],
]);

it('saves a contributor\'s page type, article type and snippet controls with their draft, as anyone who may edit it may (I-693, I-879)', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $draft = lifecycleDraft($contributor, 'Harbour news');
    $choices = [
        BuiltInFields::PAGE_TYPE => 'AboutPage',
        BuiltInFields::ARTICLE_TYPE => 'NewsArticle',
        BuiltInFields::NOSNIPPET => true,
        BuiltInFields::MAX_SNIPPET => 160,
        BuiltInFields::NOARCHIVE => true,
    ];

    edSave($draft->id, $contributor, ['type' => 'doc', 'content' => []], fields: $choices);

    expect(json_decode((string) DB::table('content_autosaves')->where('content_id', $draft->id)->value('fields'), true))->toMatchArray($choices);
});

it('draws the trail a theme draws, home, the category\'s parents and the category, then the item, as the site makes it (I-295)', function (string $category): void {
    $id = match ($category) {
        'a category under another' => $this->local->id,
        'a top-level category' => $this->sport->id,
        default => null,
    };

    $browser = guardNode('search-preview.mjs', ['trail' => ['terms' => app(TermPicker::class)->tree('category'), 'categoryId' => $id, 'title' => 'Harbour news']])['trail'];
    $site = array_map(fn (Crumb $crumb): string => $crumb->name, app(Breadcrumbs::class)->toItem('Harbour news', '/harbour-news/', $id));

    expect($browser)->toBe($site);
})->with(['a category under another', 'a top-level category', 'no category']);

it('names the first ticked category when the chosen one isn\'t ticked, as the site picks it (I-869)', function (): void {
    $session = ['taxonomies' => [['key' => 'category', 'hierarchical' => true], ['key' => 'tag', 'hierarchical' => false]], 'fields' => [BuiltInFields::PRIMARY_CATEGORY => $this->news->id]];
    $terms = [['id' => $this->sport->id, 'name' => 'Sport', 'taxonomy' => 'category'], ['id' => $this->local->id, 'name' => 'Local', 'taxonomy' => 'category']];

    $browser = guardNode('search-preview.mjs', ['picked' => [...$session, 'terms' => $terms]])['picked'];

    expect($browser)->toBe(['id' => $this->sport->id, 'name' => 'Sport'])
        ->and(PrimaryCategory::pick([$this->sport->id, $this->local->id], $this->news->id))->toBe($browser['id']);
});
