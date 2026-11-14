<?php

declare(strict_types=1);

use App\Domain\Cache\Footprints;
use App\Domain\Cache\PageTags;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('tags a published page with its parent\'s children list', function (): void {
    $folder = cacheOn();
    $tree = PageTree::build();

    expect(Footprints::content($tree->team))->toContain('children:'.$tree->about->id)
        ->and(PageTags::childrenOf($tree->about->id))->toBe('children:'.$tree->about->id)
        ->and(PageTags::isList(PageTags::childrenOf($tree->about->id)))->toBeTrue()
        ->and(Footprints::content($tree->about))->not->toContain('children:');

    cacheOff($folder);
});

it('builds the fixture with every address as D4 gives it', function (): void {
    $tree = PageTree::build();

    expect([
        addrCurrent('content', $tree->about->id),
        addrCurrent('content', $tree->team->id),
        addrCurrent('content', $tree->people->id),
        addrCurrent('content', $tree->history->id),
        addrCurrent('content', $tree->plans->id),
        addrCurrent('content', $tree->secret->id),
        addrCurrent('content', $tree->secretChild->id),
        addrCurrent('content', $tree->services->id),
        addrCurrent('content', $tree->web->id),
        addrCurrent('content', $tree->archived->id),
        addrCurrent('content', $tree->archivedChild->id),
        addrCurrent('content', $tree->teamPost->id),
    ])->toBe(['about', 'about/team', 'about/team/people', 'about/history', null, null, 'about/secret-child', null, 'web', null, 'about/archived-child', 'team']);
});
