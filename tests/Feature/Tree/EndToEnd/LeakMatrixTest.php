<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Site\ArticleViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Tree\FamilyTheme;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    Switches::apiOn();
    Switches::llmsOn();
    FamilyTheme::activate();
});

/**
 * Every surface that could show the page or its parent, each asked twice
 * so a stored copy answers the second time when the cache is on: its HTML
 * with its JSON-LD, its view data, the API's list of pages, its item by ID
 * and by address, the API's list of the parent's children but for that
 * list's own address, llms.txt, its Markdown copy, and the pages' sitemap.
 *
 * @return array<string, string>
 */
function lmSurfaces(Content $page, Content $parent): array
{
    $page->refresh();
    $address = '/'.Paths::current('content', $page->id).'/';
    $twice = fn (string $uri): string => implode("\n", array_map(fn (): string => (string) addrGet($uri)->assertOk()->getContent(), [1, 2]));
    $json = fn (string $uri): string => implode("\n", array_map(fn (): string => (string) test()->get($uri)->assertOk()->getContent(), [1, 2]));
    $list = test()->getJson("/api/v1/items?parent={$parent->id}")->assertOk()->json();
    unset($list['links']['self']);

    return [
        'HTML' => $twice($address),
        'view data' => (string) json_encode(app(ArticleViews::class)->published($page), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        '/api/v1/items' => $json('/api/v1/items?type=page&limit=100'),
        '/api/v1/items/{id}' => $json("/api/v1/items/{$page->id}"),
        '/api/v1/items/by-path' => $json('/api/v1/items/by-path?path='.rawurlencode($address)),
        '?parent=' => (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'llms.txt' => $twice('/llms.txt'),
        '.md' => $twice(rtrim($address, '/').'.md'),
        'sitemap' => $twice('/sitemap-items-page.xml'),
    ];
}

/**
 * The ways the parent could show: its ID, its address's last part as a
 * step of an address, and its title as a whole name in JSON, HTML or
 * Markdown.
 *
 * @return list<string>
 */
function lmMarks(Content $parent): array
{
    $parent->refresh();
    $title = (string) $parent->title;

    return [(string) $parent->id, '/'.$parent->slug.'/', '"'.$title.'"', '>'.$title.'<', '['.$title.']', '# '.$title."\n"];
}

it('never shows a hidden parent on any surface, for any reason it is hidden', function (bool $cached): void {
    $folder = $cached ? cacheOn() : null;
    $tree = $this->tree;

    // Private, a draft, in the trash.
    foreach ([[$tree->secretChild, $tree->secret], [$tree->web, $tree->services], [$tree->archivedChild, $tree->archived]] as [$page, $parent]) {
        foreach (lmSurfaces($page, $parent) as $surface => $shown) {
            foreach (lmMarks($parent) as $mark) {
                expect(str_contains($shown, $mark))->toBeFalse("{$page->title}'s {$surface} shows {$mark}");
            }
        }
    }

    // So the walk can't pass empty: a live parent shows on the surfaces that name a page's parent.
    $shown = lmSurfaces($tree->team, $tree->about);

    foreach (['/api/v1/items', '/api/v1/items/{id}', '/api/v1/items/by-path'] as $surface) {
        expect($shown[$surface])->toContain($tree->about->id, '/about/', '"About"');
    }

    expect($shown['view data'])->toContain('"title":"About","url":"/about/"')
        ->and($shown['HTML'])->toContain('<a href="/about/">About</a>', '"name":"About"')
        ->and($shown['?parent='])->toContain($tree->team->id)
        ->and($shown['llms.txt'])->toContain('/about/team/')
        ->and($shown['.md'])->toContain('/about/team/')
        ->and($shown['sitemap'])->toContain('/about/team/');

    if ($folder !== null) {
        cacheOff($folder);
    }
})->with(['page cache on' => true, 'page cache off' => false]);

/**
 * The answer, with what was asked for in it put back as a placeholder, so
 * a hidden item's answer and an unknown one's can be compared whole.
 */
function lmAnswer(string $uri, string $asked): array
{
    $response = test()->get($uri);

    return [$response->getStatusCode(), str_replace([$asked, rawurlencode($asked)], '{asked}', (string) $response->getContent())];
}

it('answers a hidden parent asked for directly exactly as one that never existed', function (bool $cached): void {
    $folder = $cached ? cacheOn() : null;
    $tree = $this->tree;
    $unknownId = '01jcb7y1r9n3w4m5p6q7s8t9v0';

    foreach ([$tree->secret, $tree->services, $tree->archived] as $parent) {
        $parent->refresh();
        // Every address it ever had, and the ones it would have had.
        $addresses = array_values(array_unique([
            ...DB::table('paths')->where('target_type', 'content')->where('target_id', $parent->id)->pluck('path')->map(fn (string $path): string => '/'.$path.'/')->all(),
            '/'.$parent->slug.'/', '/about/'.$parent->slug.'/',
        ]));

        expect(lmAnswer("/api/v1/items/{$parent->id}", $parent->id))->toBe(lmAnswer("/api/v1/items/{$unknownId}", $unknownId));

        foreach ($addresses as $address) {
            expect(lmAnswer('/api/v1/items/by-path?path='.rawurlencode($address), $address))
                ->toBe(lmAnswer('/api/v1/items/by-path?path='.rawurlencode('/never-was/'), '/never-was/'));

            $site = addrGet($address);

            expect($site->getStatusCode())->toBe(404, "{$parent->title} at {$address}")
                ->and(str_contains((string) $site->getContent(), '>'.$parent->title.'<'))->toBeFalse()
                ->and(str_contains((string) $site->getContent(), '"'.$parent->title.'"'))->toBeFalse();
        }
    }

    if ($folder !== null) {
        cacheOff($folder);
    }
})->with(['page cache on' => true, 'page cache off' => false]);

it('shows nothing of a parent hidden after its children\'s answers were stored', function (string $store): void {
    $done = $store === 'file' ? cacheOn() : cacheOnRedis();
    $tree = $this->tree;
    // People, under Team: hidden, Team leaves People's address, which People's new one, about/people, is free to
    // take (About's own pages would keep theirs: the post Team holds /team/, the Security section's exception 3).
    $surfaces = fn (): array => lmSurfaces($tree->people, $tree->team);

    // Stored first, with Team on the site.
    expect($surfaces()['HTML'])->toContain('<a href="/about/team/">Team</a>');

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($tree->team->id, 'private', null, (string) $tree->editor->id));

    expect(Paths::current('content', $tree->people->id))->toBe('about/people');

    // The post Team, a different item that readers may see, is listed in llms.txt by its title: the page is
    // looked for there by its own link.
    $marks = [...array_diff(lmMarks($tree->team), ['[Team]']), '[Team](http://localhost/about/team'];

    foreach ($surfaces() as $surface => $shown) {
        foreach ($marks as $mark) {
            expect(str_contains($shown, $mark))->toBeFalse("People's {$surface} shows {$mark}");
        }
    }

    $store === 'file' ? cacheOff($done) : cacheOffValkey($done);
})->with(['file store' => 'file', 'Redis store' => 'redis']);
