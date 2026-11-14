<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Site\LatestViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('leads the latest posts with the newest pinned post, then up to three more, then the rest', function (): void {
    pageArticle($this->author, 'Old pin', sticky: true);
    $this->travel(1)->hours();
    pageArticle($this->author, 'Pin A', sticky: true);
    $this->travel(1)->hours();
    pageArticle($this->author, 'Pin B', sticky: true);
    foreach (range(1, 10) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "Plain {$i}");
    }

    $latest = app(LatestViews::class)->latest(1);
    $feed = array_map(fn ($item) => $item->title, $latest->feed->items);

    expect($latest->lead->title)->toBe('Pin B')
        ->and(array_map(fn ($item) => $item->title, $latest->featured))->toBe(['Pin A', 'Old pin'])
        ->and($feed)->not->toContain('Pin B')->not->toContain('Pin A')->not->toContain('Old pin')
        ->and($feed[0])->toBe('Plain 10')
        ->and($latest->feed->subject)->toBeNull();
});

it('leads with the newest post when nothing is pinned', function (): void {
    pageArticle($this->author, 'Earlier');
    $this->travel(1)->minutes();
    pageArticle($this->author, 'Latest');

    expect(app(LatestViews::class)->latest(1)->lead->title)->toBe('Latest');
});

it('shows the published title, never a newer draft\'s', function (): void {
    $post = pageArticle($this->author, 'Published title');
    pageSaveDraft($post, 'Draft title');

    expect(app(LatestViews::class)->latest(1)->lead->title)->toBe('Published title');
});

it('leads the posts page with the pins too, titled with that page\'s title', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $welcome = addrPublished($admin, 'Welcome', 'page');
    $news = addrPublished($admin, 'News', 'page');
    addrChange($admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id, 'postsPageId' => $news->id]);
    pageArticle($this->author, 'Pinned story', sticky: true);
    $this->travel(1)->minutes();
    pageArticle($this->author, 'Plain story');

    $latest = app(LatestViews::class)->latest(1, $news);

    expect($latest->lead->title)->toBe('Pinned story')
        ->and($latest->feed->subject->kind)->toBe('posts')
        ->and($latest->feed->subject->title)->toBe('News')
        ->and($latest->feed->items[0]->title)->toBe('Plain story');
});

it('finds the pinned posts in their own index, never by walking every published post (1.6h)', function (): void {
    pageArticle($this->author, 'Pinned', sticky: true);
    foreach (range(1, 3) as $i) {
        pageArticle($this->author, "Plain {$i}");
    }
    planFillerPosts(User::factory()->create());

    // Sticky is a built-in field in each item's fields (I-156): the index is
    // on that JSONB value, for published, live items, never a column.
    $plan = planOf(fn () => app(LatestViews::class)->latest(1), "'true'");

    expect(planReadsIndex($plan, 'contents_pinned_published', 'contents'))->toBeTrue($plan)
        ->and($plan)->not->toContain('Filter');
});

it('uses the pinned index even for a statement planned without its values (1.6h)', function (): void {
    pageArticle($this->author, 'Pinned', sticky: true);
    planFillerPosts(User::factory()->create());

    // A generic plan can't prove a bound value meets the index's condition:
    // the statement writes that condition itself.
    $plan = genericPlanOf(fn () => app(LatestViews::class)->latest(1), "'true'");

    expect(planReadsIndex($plan, 'contents_pinned_published', 'contents'))->toBeTrue($plan);
});
