<?php

declare(strict_types=1);

use App\Domain\Site\ReaderKeys;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->folder = cacheOn();
    $this->item = Visibilities::published($this->editor, 'Members update', 'zqprotected');
});

afterEach(fn () => cacheOff($this->folder));

it('never stores a protected item\'s page, locked or open, and removes the copy stored while it was public (I-1700)', function (): void {
    expect(cacheGet('/members-update/')->headers->get('X-Teme-Cache'))->toBe('MISS')
        ->and(cacheEntry('/members-update/'))->not->toBeNull();

    $this->item = Visibilities::change($this->item, 'protected', $this->editor, 'Harbour 2024');

    expect(cacheEntry('/members-update/'))->toBeNull();

    $locked = cacheGet('/members-update/');
    $open = cacheGet('/members-update/', [ReaderKeys::COOKIE => Visibilities::cookie($this->item)]);
    $again = cacheGet('/members-update/');

    expect($locked->headers->get('X-Teme-Cache'))->toBe('BYPASS (private)')
        ->and($open->headers->get('X-Teme-Cache'))->toBe('BYPASS (private)')
        ->and($again->headers->get('X-Teme-Cache'))->toBe('BYPASS (private)')
        ->and((string) $again->getContent())->not->toContain('zqprotectedbody')
        ->and(cacheEntry('/members-update/'))->toBeNull();
});

it('stores the page again once the item is public again (I-1700)', function (): void {
    Visibilities::change($this->item, 'protected', $this->editor, 'Harbour 2024');
    cacheGet('/members-update/');
    Visibilities::change($this->item, 'public', $this->editor);

    expect(cacheGet('/members-update/')->headers->get('X-Teme-Cache'))->toBe('MISS')
        ->and((string) cacheGet('/members-update/')->getContent())->toContain('zqprotectedbody');
});

it('removes a listing page that showed a protected item\'s excerpt once it is protected (I-1700)', function (): void {
    expect((string) cacheGet('/')->getContent())->toContain('zqprotectedexcerpt')
        ->and(cacheEntry('/'))->not->toBeNull();

    Visibilities::change($this->item, 'protected', $this->editor, 'Harbour 2024');

    expect((string) cacheGet('/')->getContent())->not->toContain('zqprotectedexcerpt');
});
