<?php

declare(strict_types=1);

use App\Domain\Cache\PageKey;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('tags an article with itself, its author, the terms it shows, the lists its related posts come from, and the site\'s settings and design', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $herons = typesTerm($this->editor, 'tag', 'Herons');
    $story = pageArticle($this->editor, 'Big story', categories: [$news], tags: [$herons]);

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/big-story/')?->tags)
        ->toContain(
            (string) PageKey::of(Request::create('/big-story/'))?->urlTag(),
            'content:'.$story->id,
            'person:'.$this->editor->id,
            'term:'.$news->id,
            'term:'.$herons->id,
            'term:category:'.$news->id,
            'term:tag:'.$herons->id,
            'settings',
            'theme',
        )
        ->not->toContain('*');
});

it('tags a page with each image it draws and each item it links to, found or not', function (): void {
    $lead = pageImage($this->editor, 1200, 800);
    $inBody = pageImage($this->editor, 800, 600);
    $notes = pageArticle($this->editor, 'Harbour notes');
    pageArticleFromDocument($this->editor, 'Big story', [pageImageNode($inBody, (string) Str::ulid()), schedLinkTo($notes->id, 'the notes')], $lead);

    // A trashed image draws as nothing, yet the page changes when it comes back.
    app(TrashMedia::class)->execute(new MediaActionInput($inBody->id, (string) $this->editor->id));

    addrGet('/big-story/')->assertOk();

    expect(cacheEntry('/big-story/')?->tags)->toContain('media:'.$lead->id, 'media:'.$inBody->id, 'content:'.$notes->id);
});

it('tags every page with each menu it draws and what the menu\'s items point at', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $news = typesTerm($this->editor, 'category', 'News');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home'], ['kind' => 'content', 'target_id' => $about->id], ['kind' => 'term', 'target_id' => $news->id]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    addrGet('/')->assertOk();

    expect(cacheEntry('/')?->tags)->toContain('menu:'.$nav->handle, 'content:'.$about->id, 'term:'.$news->id);
});

it('tags a page with each region it draws, whether or not the region holds anything yet', function (): void {
    pageArticle($this->editor, 'Big story');

    addrGet('/big-story/')->assertOk();

    expect(cacheEntry('/big-story/')?->tags)->toContain('region:good--after-content');
});

it('tags the latest posts with the list of their type and each post they show', function (): void {
    $first = pageArticle($this->editor, 'First story');
    $second = pageArticle($this->editor, 'Second story');

    addrGet('/')->assertOk();

    expect(cacheEntry('/')?->tags)->toContain('list:post', 'content:'.$first->id, 'content:'.$second->id, 'person:'.$this->editor->id);
});

it('tags a term\'s archive with the list of what is filed under it, and the term', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $story = pageArticle($this->editor, 'Big story', categories: [$news]);

    addrGet('/category/news/')->assertOk();

    expect(cacheEntry('/category/news/')?->tags)->toContain('term:category:'.$news->id, 'term:'.$news->id, 'content:'.$story->id);
});

it('tags an author\'s archive with the list of what they wrote, and the person', function (): void {
    // Their bio gives them their address, as dtSite() does.
    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $this->editor->id, 'Jane Doe', $this->editor->email, (string) $this->editor->id, 'Jane writes about the harbour.'));
    pageArticle($this->editor, 'Big story');

    addrGet('/author/jane-doe/')->assertOk();

    expect(cacheEntry('/author/jane-doe/')?->tags)->toContain('author:'.$this->editor->id, 'person:'.$this->editor->id);
});

it('tags a date archive with its year\'s list, or its month\'s for a month or a day', function (): void {
    pageArticle($this->editor, 'Big story');
    $year = '/'.now()->format('Y').'/';
    $month = '/'.now()->format('Y/m').'/';
    $day = '/'.now()->format('Y/m/d').'/';

    addrGet($year)->assertOk();
    addrGet($month)->assertOk();
    addrGet($day)->assertOk();

    expect(cacheEntry($year)?->tags)->toContain('date:'.now()->format('Y'))
        ->and(cacheEntry($month)?->tags)->toContain('date:'.now()->format('Y:m'))
        ->and(cacheEntry($day)?->tags)->toContain('date:'.now()->format('Y:m'));
});

it('tags a search with the list of each type it searches', function (): void {
    $found = searchPublished($this->editor, 'Harbour notes', 'The lifeboat crew launched at dawn.');

    addrGet('/?s=lifeboat')->assertOk();

    expect(cacheEntry('/?s=lifeboat')?->tags)->toContain('list:post', 'list:page', 'content:'.$found->id)
        ->not->toContain('list:region');
});

it('tags a "not found" page with its address, the settings and the design, and with nothing it doesn\'t show', function (): void {
    addrGet('/nowhere/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/nowhere/')?->tags)->toContain((string) PageKey::of(Request::create('/nowhere/'))?->urlTag(), 'settings', 'theme')
        ->not->toContain('list:post');
});

it('tags the page chosen as the home page with itself, and the posts page with the latest posts\' list and itself', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $home = addrPublished($this->editor, 'Welcome', 'page');
    $blog = addrPublished($this->editor, 'Blog', 'page');
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $home->id, 'postsPageId' => $blog->id]);

    addrGet('/')->assertOk();
    addrGet('/blog/')->assertOk();

    expect(cacheEntry('/')?->tags)->toContain('content:'.$home->id)
        ->and(cacheEntry('/blog/')?->tags)->toContain('list:post', 'content:'.$blog->id);
});
