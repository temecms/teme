<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Contents\Models\Content;
use App\Domain\Site\ArticleViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
    Switches::apiOn();
    sdTrailTheme();
});

/**
 * Everything a reader can be given about the page: its HTML with its
 * JSON-LD, drawn twice so a stored copy answers the second time when the
 * cache is on, its view data, its API item, and the API's list of the
 * parent's children but for its own address.
 */
function hiddenSurfaces(Content $page, Content $parent): string
{
    $page->refresh();
    $address = (string) Paths::current('content', $page->id);
    $html = '';

    foreach ([1, 2] as $time) {
        $html .= (string) addrGet('/'.$address.'/')->assertOk()->getContent();
    }

    // The list's own address echoes what was asked for, as an unknown ID's does.
    $list = test()->getJson("/api/v1/items?parent={$parent->id}")->assertOk()->json();
    unset($list['links']['self']);

    return implode("\n", [
        $html,
        (string) json_encode(app(ArticleViews::class)->published($page), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (string) test()->get("/api/v1/items/{$page->id}")->assertOk()->getContent(),
        (string) json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * The ways the parent could show: its ID, its address's last part as a
 * step of an address, and its title as a whole name.
 *
 * @return list<string>
 */
function hiddenMarks(Content $parent): array
{
    $parent->refresh();
    $title = (string) $parent->title;

    return [(string) $parent->id, '/'.$parent->slug.'/', '"'.$title.'"', '>'.$title.'<'];
}

it('never shows a hidden parent in a page\'s address, trail, view data or API answer', function (bool $cached): void {
    $folder = $cached ? cacheOn() : null;
    $tree = $this->tree;

    foreach ([[$tree->secretChild, $tree->secret], [$tree->archivedChild, $tree->archived], [$tree->web, $tree->services]] as [$page, $parent]) {
        $shown = hiddenSurfaces($page, $parent);

        foreach (hiddenMarks($parent) as $mark) {
            expect(str_contains($shown, $mark))->toBeFalse("{$page->title} shows {$mark}");
        }
    }

    // So the walk can't pass empty: a live parent shows in every surface.
    $shown = hiddenSurfaces($tree->team, $tree->about);

    expect($shown)->toContain($tree->about->id, '/about/', '"About"', '>About<');

    if ($folder !== null) {
        cacheOff($folder);
    }
})->with(['page cache on' => true, 'page cache off' => false]);
