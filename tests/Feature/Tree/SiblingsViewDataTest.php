<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\ItemLink;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

function siblingView(Content $page): ArticleView
{
    return app(ArticleViews::class)->published($page->refresh());
}

/**
 * @param  list<ItemLink>  $links
 *
 * @return list<string>
 */
function siblingTitles(array $links): array
{
    return array_map(fn (ItemLink $link): string => $link->title, $links);
}

it('lists a page\'s siblings, itself among them, in menu order, leaving out those readers can\'t see', function (): void {
    expect(siblingTitles(siblingView($this->tree->team)->siblings))->toBe(['Team', 'History'])
        ->and(siblingTitles(siblingView($this->tree->history)->siblings))->toBe(['Team', 'History'])
        ->and(siblingView($this->tree->team)->siblings[1]->url)->toBe('/about/history/');
});

it('gives the sibling before and the one after, null at the ends', function (): void {
    $team = siblingView($this->tree->team);
    $history = siblingView($this->tree->history);

    expect($team->previous)->toBeNull()
        ->and($team->next?->title)->toBe('History')
        ->and($history->previous?->title)->toBe('Team')
        ->and($history->previous?->url)->toBe('/about/team/')
        ->and($history->next)->toBeNull();
});

it('follows the menu order after a page is moved, not the title', function (): void {
    PageTree::place($this->tree->history, $this->tree->about, 0);
    PageTree::place($this->tree->team, $this->tree->about, 1);

    expect(siblingTitles(siblingView($this->tree->team)->siblings))->toBe(['History', 'Team'])
        ->and(siblingView($this->tree->team)->previous?->title)->toBe('History')
        ->and(siblingView($this->tree->history)->next?->title)->toBe('Team');
});

it('has no siblings for a page that is alone, and none for a type that doesn\'t nest', function (): void {
    $people = siblingView($this->tree->people);

    expect($people->siblings)->toHaveCount(1)
        ->and($people->previous)->toBeNull()
        ->and($people->next)->toBeNull();

    $post = siblingView($this->tree->teamPost);

    expect($post->siblings)->toBe([])
        ->and($post->previous)->toBeNull()
        ->and($post->next)->toBeNull();
});

it('keeps top-level pages to their own type, and sets the page aside when a sibling changes', function (): void {
    $about = siblingView($this->tree->about);

    expect(siblingTitles($about->siblings))->toBe(['About']);
});
