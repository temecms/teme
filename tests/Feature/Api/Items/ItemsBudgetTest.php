<?php

declare(strict_types=1);

use App\Domain\Cards\CardMedia;
use App\Domain\Media\Models\Media;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = ctAdmin();
    Switches::apiOn();
    $this->category = typesTerm($this->admin, 'category', 'News');
    $this->tag = typesTerm($this->admin, 'tag', 'Herons');
    $this->image = mediaUpload($this->admin, mediaJpeg(1200, 800), 'Heron.jpg');
});

/**
 * Published posts by this person, up to $total, each with a category, a tag
 * and a featured image.
 */
function itemsBudgetPosts(User $author, int $total, Term $category, Term $tag, Media $image): void
{
    $have = (int) DB::table('contents')->where('author_id', $author->id)->count();

    for ($n = $have + 1; $n <= $total; $n++) {
        pageArticle($author, "Post {$n}", 5, "Standfirst {$n}", [$category], [$tag], $image);
    }
}

/**
 * The statements one drawn answer at this address makes, the registries
 * and settings already read by an earlier one.
 */
function itemsBudgetCount(string $address): int
{
    test()->getJson($address)->assertOk();
    // The files a request has read are kept for that request only (CardMedia); a drawn answer reads its own.
    app()->forgetInstance(CardMedia::class);

    return count(planStatements(fn () => test()->getJson($address)->assertOk()));
}

it('reads a page of items in at most 9 statements, the same for 1 item and 100', function (): void {
    itemsBudgetPosts($this->admin, 1, $this->category, $this->tag, $this->image);
    $one = itemsBudgetCount('/api/v1/items?limit=100');
    itemsBudgetPosts($this->admin, 100, $this->category, $this->tag, $this->image);
    $hundred = itemsBudgetCount('/api/v1/items?limit=100');

    expect($this->getJson('/api/v1/items?limit=100')->json('data'))->toHaveCount(100)
        ->and($one)->toBeLessThanOrEqual(9)
        ->and($hundred)->toBe($one);
});

it('reads a filtered page in at most 10 statements, the same for 1 item and 100', function (): void {
    $slug = (string) DB::table('users')->where('id', $this->admin->id)->value('slug');
    itemsBudgetPosts($this->admin, 1, $this->category, $this->tag, $this->image);
    $term = itemsBudgetCount('/api/v1/items?limit=100&term='.$this->category->id);
    $author = itemsBudgetCount('/api/v1/items?limit=100&author='.$slug);
    // A person with only a draft costs what no such person costs, so neither is told.
    $drafter = User::factory()->create(['name' => 'Bo Lee'])->assignRole('author');
    lifecycleDraft($drafter, 'Not yet');
    $unpublished = itemsBudgetCount('/api/v1/items?limit=100&author='.(string) DB::table('users')->where('id', $drafter->id)->value('slug'));
    $nobody = itemsBudgetCount('/api/v1/items?limit=100&author=nobody');
    itemsBudgetPosts($this->admin, 100, $this->category, $this->tag, $this->image);

    expect($this->getJson('/api/v1/items?limit=100&term='.$this->category->id)->json('data'))->toHaveCount(100)
        ->and($term)->toBeLessThanOrEqual(10)
        ->and($author)->toBeLessThanOrEqual(10)
        ->and(itemsBudgetCount('/api/v1/items?limit=100&term='.$this->category->id))->toBe($term)
        ->and(itemsBudgetCount('/api/v1/items?limit=100&author='.$slug))->toBe($author)
        ->and($nobody)->toBe($unpublished);
});
