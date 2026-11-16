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

it('marks both cards, and only them, as region cards for the editor', function (): void {
    $cards = collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->pluck('regionOnly', 'name')->all();
    $regionOnly = array_keys(array_filter($cards));

    expect($regionOnly)->toEqualCanonicalizing(['site-code', 'featured-posts'])
        ->and(array_filter(app(CardRegistry::class)->all(), fn ($card): bool => $card instanceof RegionCard))->toHaveCount(2)
        ->and(collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->firstWhere('name', 'featured-posts'))
        ->toMatchArray(['label' => 'Featured posts', 'menuLabel' => 'Featured posts', 'icon' => 'list', 'inMenus' => true])
        ->and(collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->firstWhere('name', 'site-code'))
        ->toMatchArray(['label' => 'Custom HTML', 'menuLabel' => 'Custom HTML', 'icon' => 'code-xml', 'inMenus' => true]);
});
