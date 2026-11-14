<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\MoveSubtreeAddresses;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Tree\FamilyTheme;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    Switches::apiOn();
    FamilyTheme::activate();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

/**
 * The page's HTML, drawn once to store it, then read again from the store
 * or drawn afresh as the cache decides.
 */
function purgesShown(string $uri): string
{
    return (string) addrGet($uri)->assertOk()->getContent();
}

it('shows a renamed parent\'s new name on every page whose trail names it', function (): void {
    $pages = ['/about/', '/about/team/', '/about/team/people/', '/about/history/'];

    foreach ($pages as $uri) {
        expect(purgesShown($uri))->toContain('>About<');
    }

    $about = $this->tree->about->refresh();
    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        contentId: $about->id,
        expectedRevisionId: (string) $about->working_revision_id,
        actorId: (string) $this->tree->editor->id,
        title: 'About us',
    ));

    foreach ($pages as $uri) {
        expect(purgesShown($uri))->toContain('>About us<')->not->toContain('>About<');
    }
});

it('lists a newly published child on its parent\'s page at once', function (): void {
    expect(purgesShown('/about/'))->toContain('>Team<')->not->toContain('>Careers<');

    $careers = lifecycleDraft($this->tree->editor, 'Careers', 'page');
    PageTree::place($careers, $this->tree->about, 5);
    listsPublish($careers, $this->tree->editor);

    expect(purgesShown('/about/'))->toContain('<a href="/about/careers/">Careers</a>');
});

it('moves a page and answers both its addresses at once, old with 301', function (): void {
    purgesShown('/about/team/people/');
    purgesShown('/about/team/');
    purgesShown('/about/history/');

    PageTree::place($this->tree->people, $this->tree->history);
    $old = addrGet('/about/team/people/');

    expect($old->getStatusCode())->toBe(301)
        ->and(addrLocation($old))->toBe('/about/history/people/')
        ->and(purgesShown('/about/history/people/'))->toContain('<a href="/about/history/">History</a>')
        ->and(purgesShown('/about/team/'))->not->toContain('>People<')
        ->and(purgesShown('/about/history/'))->toContain('<a href="/about/history/people/">People</a>');
});

it('lists a moved page at its new address in the sitemap on its next draw', function (): void {
    $before = smLocs((string) addrGet('/sitemap-items-page.xml')->assertOk()->getContent());

    // The second draw comes from the store, so the next one is fresh only if the move set it aside.
    addrGet('/sitemap-items-page.xml')->assertHeader('X-Teme-Cache', 'HIT');

    expect($before)->toContain('http://localhost/about/team/people/');

    PageTree::place($this->tree->people, $this->tree->history);
    $after = smLocs((string) addrGet('/sitemap-items-page.xml')->assertOk()->getContent());

    expect($after)->toContain('http://localhost/about/history/people/')
        ->not->toContain('http://localhost/about/team/people/');
});

it('answers the API\'s ?parent= list with a newly published child at once', function (): void {
    $list = fn (): array => array_column($this->getJson("/api/v1/items?parent={$this->tree->about->id}")->assertOk()->json('data'), 'title');

    expect($list())->toEqualCanonicalizing(['Team', 'History']);

    $careers = lifecycleDraft($this->tree->editor, 'Careers', 'page');
    PageTree::place($careers, $this->tree->about, 5);
    listsPublish($careers, $this->tree->editor);

    expect($list())->toEqualCanonicalizing(['Team', 'History', 'Careers']);
});

it('moves a branch of 120 in the background and serves each page meanwhile', function (): void {
    Queue::fake([MoveSubtreeAddresses::class]);
    PageTree::many($this->tree->about, 120, withAddresses: true);
    $pages = ['/about/team/' => '/about-us/team/', '/about/team/people/' => '/about-us/team/people/', '/about/history/' => '/about-us/history/'];

    foreach (array_keys($pages) as $uri) {
        purgesShown($uri);
    }

    addrRename($this->tree->about, 'about-us', $this->tree->editor);

    // The job waits: each page still answers at its old address, its trail naming About at its new one.
    foreach (array_keys($pages) as $uri) {
        expect(purgesShown($uri))->toContain('<a href="/about-us/">About</a>');
    }

    Queue::assertPushed(MoveSubtreeAddresses::class, 1);
    app()->forgetScopedInstances();
    app()->call([Queue::pushed(MoveSubtreeAddresses::class)->first(), 'handle']);

    foreach ($pages as $old => $new) {
        $moved = addrGet($old);

        expect($moved->getStatusCode())->toBe(301)
            ->and(addrLocation($moved))->toBe($new)
            ->and(purgesShown($new))->toContain('<a href="/about-us/">About</a>');
    }
});
