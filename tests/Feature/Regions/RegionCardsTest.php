<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\RegionCard;
use App\Domain\Contents\Models\ContentRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

dataset('regionCards', [
    'custom HTML' => [fn (): array => ['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'entryId' => strtolower((string) Str::ulid())]]],
    'featured posts' => [fn (): array => ['type' => 'featured-posts', 'attrs' => ['id' => (string) Str::ulid(), 'heading' => 'More to read', 'source' => 'latest', 'type' => 'post', 'count' => 3]]],
]);

it('refuses a region card in a post, and writes nothing', function (Closure $node): void {
    $post = lifecycleDraft($this->editor, 'A story');
    $working = $post->working_revision_id;
    $revisions = ContentRevision::query()->count();

    expect(fn () => docSave($post, $this->editor, docWith(docText('Before'), $node())))
        ->toThrow(ValidationException::class, 'This card goes in a theme\'s region only.')
        ->and($post->refresh()->working_revision_id)->toBe($working)
        ->and(ContentRevision::query()->count())->toBe($revisions);
})->with('regionCards');

it('refuses a region card in a post\'s autosave too, unfinished or not', function (Closure $node): void {
    $post = lifecycleDraft($this->editor, 'A story');

    expect(fn () => edSave($post->id, $this->editor, docWith($node())))
        ->toThrow(ValidationException::class, 'This card goes in a theme\'s region only.');
})->with('regionCards');

it('saves a region card in a region whose declaration lists it', function (Closure $node): void {
    $region = regionCreate($this->admin, 'default');
    $created = $region->working_revision_id;

    regionSave($region, $this->editor, [$node()]);

    expect($region->refresh()->working_revision_id)->not->toBe($created);
})->with('regionCards');

it('refuses a region card in a region whose declaration doesn\'t list it, as for any card', function (Closure $node): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $region = regionCreate($this->editor);

    expect(fn () => regionSave($region, $this->editor, [$node()]))
        ->toThrow(ValidationException::class, 'can\'t be used in this region');
})->with('regionCards');

it('refuses a featured list that chooses no posts, counts past twelve, or lists a type the site doesn\'t show', function (array $attrs, string $message): void {
    $region = regionCreate($this->admin, 'default');
    $node = ['type' => 'featured-posts', 'attrs' => ['id' => (string) Str::ulid(), ...$attrs]];

    expect(fn () => regionSave($region, $this->editor, [$node]))->toThrow(ValidationException::class, $message);
})->with([
    'chosen, none' => [['source' => 'chosen', 'items' => []], 'Choose up to 12 posts'],
    'thirteen' => [['count' => 13], 'count'],
    'not an ID' => [['source' => 'chosen', 'items' => ['https://example.com/a-post/']], 'Choose posts from this site.'],
    'a region' => [['type' => 'region'], 'Choose a type the site shows.'],
    'no such type' => [['type' => 'recipe'], 'Choose a type the site shows.'],
]);

it('marks the region cards, and only them, as region cards for the editor', function (): void {
    $cards = collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->pluck('regionOnly', 'name')->all();
    $regionOnly = array_keys(array_filter($cards));

    expect($regionOnly)->toEqualCanonicalizing(['site-code', 'featured-posts', 'plugin-card', 'theme-card'])
        ->and(array_filter(app(CardRegistry::class)->all(), fn ($card): bool => $card instanceof RegionCard))->toHaveCount(4)
        ->and(collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->firstWhere('name', 'featured-posts'))
        ->toMatchArray(['label' => 'Featured posts', 'menuLabel' => 'Featured posts', 'icon' => 'list', 'inMenus' => true])
        ->and(collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->firstWhere('name', 'site-code'))
        ->toMatchArray(['label' => 'Custom HTML', 'menuLabel' => 'Custom HTML', 'icon' => 'code-xml', 'inMenus' => true]);
});

it('sends the editor in region mode the code placed with a card, by name and state, and never the code (I-3127)', function (): void {
    $ad = scAdd($this->admin, 'Sidebar ad', 'placed', '<script>window.secret=1</script>');
    $off = scAdd($this->admin, 'Old banner', 'placed', '<div>Old</div>', on: false);
    scAdd($this->admin, 'Analytics', 'head', '<script>head()</script>');
    $region = regionCreate($this->admin, 'default');

    $response = $this->actingAs($this->editor)->get("/admin/content/{$region->id}/edit")->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('placedCode', [
            ['id' => $ad->id, 'name' => 'Sidebar ad', 'on' => true],
            ['id' => $off->id, 'name' => 'Old banner', 'on' => false],
        ])
        ->where('siteCodeLocked', false));
    expect((string) $response->getContent())->not->toContain('window.secret');

    config(['teme.disallow_site_code' => true]);

    $this->actingAs($this->editor)->get("/admin/content/{$region->id}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('siteCodeLocked', true));
});

it('sends no placed code to the editor outside region mode', function (): void {
    scAdd($this->admin, 'Sidebar ad', 'placed', '<div>Ad</div>');
    $post = lifecycleDraft($this->editor, 'A story');

    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('placedCode', null)->where('siteCodeLocked', null));
});
