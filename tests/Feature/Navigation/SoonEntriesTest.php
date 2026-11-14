<?php

declare(strict_types=1);

use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
use App\Domain\Navigation\Queries\AdminNavigation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
});

it('takes a planned entry without a route, and still refuses an entry with neither a route nor a hub', function (): void {
    $registry = new NavigationRegistry;
    $registry->register(new NavEntry('newsletter', 'Newsletter', 'mail', NavSection::Content, 900, soon: true));

    expect(array_map(fn (NavEntry $entry): bool => $entry->soon, $registry->all()))->toBe([true])
        ->and(fn () => $registry->register(new NavEntry('forms', 'Forms', 'clipboard-list', NavSection::Content, 910)))
        ->toThrow(InvalidArgumentException::class);
});

it('sends a planned entry with its Soon flag and no address, only to those its ability lets see it', function (): void {
    app(NavigationRegistry::class)->register(new NavEntry('newsletter', 'Newsletter', 'mail', NavSection::Content, 900, ability: 'users.manage', soon: true));

    $menu = app(AdminNavigation::class)->forUser($this->admin->id);
    $planned = array_values(array_filter($menu, fn (array $entry): bool => $entry['key'] === 'newsletter'));
    $others = array_filter($menu, fn (array $entry): bool => $entry['key'] !== 'newsletter' && array_key_exists('soon', $entry));

    expect($planned)->toHaveCount(1)
        ->and($planned[0]['soon'])->toBeTrue()
        ->and($planned[0]['href'])->toBe('')
        ->and($planned[0]['badge'])->toBe(0)
        ->and($others)->toBe([])
        ->and(array_column(app(AdminNavigation::class)->forUser($this->author->id), 'key'))->not->toContain('newsletter');
});

it('never pins a planned entry, though a person named it', function (): void {
    app(NavigationRegistry::class)->register(new NavEntry('newsletter', 'Newsletter', 'mail', NavSection::Content, 900, soon: true));
    lscrPrefer($this->admin, ['nav.pinned' => ['newsletter', 'posts']]);

    $navigation = app(AdminNavigation::class);

    expect($navigation->pinnedFor($this->admin->refresh(), $navigation->forUser($this->admin->id)))->toBe(['posts']);
});
