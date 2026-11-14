<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->withElevation();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('sets aside the lists of a taxonomy\'s terms when a term changes', function (): void {
    cacheShown(['/api-terms/' => ['taxonomy:category'], '/api-tags/' => ['taxonomy:tag']]);

    cacheRolledBack(fn () => typesTerm($this->editor, 'category', 'Ferries'));

    expect(cacheKept('/api-terms/', '/api-tags/'))->toHaveCount(2);

    typesTerm($this->editor, 'category', 'Ferries');

    expect(PageTags::termsOf('category'))->toBe('taxonomy:category')
        ->and(PageTags::isList('taxonomy:category'))->toBeTrue()
        ->and(cacheKept('/api-terms/', '/api-tags/'))->toBe(['/api-tags/'])
        ->and(cacheSetAside('/api-terms/'))->not->toBeNull();
});
