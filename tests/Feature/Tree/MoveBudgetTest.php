<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

/**
 * The statements a rename of the page runs.
 */
function budgetRename(Content $page, string $slug, User $actor): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    addrRename($page, $slug, $actor);

    return $count;
}

it('moves 50 pages in one save at most eight statements each beyond the same save of a page with none', function (): void {
    // Five of About's pages have an address; 45 more make 50. Lone, at the top like About, has none under it.
    PageTree::many($this->tree->about, 45, withAddresses: true);
    $lone = addrPublished($this->tree->editor, 'Lone', 'page');
    budgetRename($this->tree->history, 'our-history', $this->tree->editor);

    $none = budgetRename($lone, 'alone', $this->tree->editor);
    $fifty = budgetRename($this->tree->about, 'about-us', $this->tree->editor);

    expect(DB::table('paths')->where('is_current', true)->where('path', 'like', 'about-us/%')->count())->toBe(50)
        ->and(($fifty - $none) / 50)->toBeLessThanOrEqual(8);
});
