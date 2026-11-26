<?php

declare(strict_types=1);

use App\Domain\Site\SearchViews;
use App\Domain\Site\ViewModels\SearchSection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    ltTypes();
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    cmSettings(['posts_per_page' => 2], 'reading');

    foreach (range(1, 3) as $n) {
        searchPublished($this->author, "Marsh walk {$n}", 'bittern watch', 'post');
    }

    foreach (range(1, 4) as $n) {
        searchPublished($this->author, "Marsh film {$n}", 'bittern on screen', 'film');
    }

    searchPublished($this->author, 'Unrelated', 'nothing to see', 'post');
});

/**
 * @param  list<SearchSection>  $sections
 *
 * @return array<string, int>
 */
function sectionCounts(array $sections): array
{
    return array_column(array_map(fn (SearchSection $section): array => [$section->type, $section->count], $sections), 1, 0);
}

it('counts the matches of each section across every page, not just the one shown', function (): void {
    $search = app(SearchViews::class)->for('bittern', 1)->search;

    expect(sectionCounts($search->sections))->toBe(['post' => 3, 'film' => 4])
        ->and($search->allTotal)->toBe(7)
        ->and($search->section)->toBeNull()
        ->and($search->total)->toBe(7);
});

it('filters to the selected section and still counts the others', function (): void {
    $view = app(SearchViews::class)->for('bittern', 1, null, 'film');

    expect($view->search->section)->toBe('film')
        ->and($view->search->total)->toBe(4)
        ->and($view->total)->toBe(4)
        ->and(array_unique(array_map(fn ($item): string => $item->type, $view->items)))->toBe(['film'])
        ->and(sectionCounts($view->search->sections))->toBe(['post' => 3, 'film' => 4])
        ->and(array_map(fn (SearchSection $section): bool => $section->current, $view->search->sections))->toBe([false, true]);
});

it('gives each section a filter address that keeps the query, and one that clears it', function (): void {
    $search = app(SearchViews::class)->for('bittern owl', 1, null, 'film')->search;

    expect($search->sections)->toBe([]);

    $search = app(SearchViews::class)->for('bittern', 1, null, 'film')->search;

    expect($search->sections[1]->url)->toBe('/?s=bittern&section=film')
        ->and($search->sections[0]->url)->toBe('/?s=bittern&section=post')
        ->and($search->allUrl)->toBe('/?s=bittern');
});

it('keeps the section on every page of the results', function (): void {
    $pagination = app(SearchViews::class)->for('bittern', 1, null, 'film')->pagination;

    expect($pagination->next)->toBe('/page/2/?s=bittern&section=film')
        ->and($pagination->pages()[1]['url'])->toBe('/page/2/?s=bittern&section=film');
    expect(addrGet('/page/2/?s=bittern&section=film')->assertOk()->getContent())->toContain('Marsh film');
});

it('ignores a section that is not a searchable type', function (): void {
    $search = app(SearchViews::class)->for('bittern', 1, null, 'nonsense')->search;

    expect($search->section)->toBeNull()
        ->and($search->total)->toBe(7);
});

it('has no sections for a blank query', function (): void {
    $search = app(SearchViews::class)->for('  ', 1)->search;

    expect($search->sections)->toBe([])->and($search->allUrl)->toBeNull();
});

it('counts every section in one grouped statement, however many types there are', function (): void {
    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (stripos($query->sql, 'group by') !== false) {
            $queries++;
        }
    });

    app(SearchViews::class)->for('bittern', 1);

    expect($queries)->toBe(1);
});
