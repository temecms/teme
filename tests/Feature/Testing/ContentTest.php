<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Teme\Contracts\Content\ItemRef;
use Teme\Testing\InteractsWithTeme;
use Teme\Testing\SentRequest;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class, InteractsWithTeme::class);

beforeEach(fn () => config(['teme.version' => PluginFixtures::TEME]));

afterEach(fn () => PluginFixtures::forget());

it('publishes through the Operations, a running plugin hears it, and the item has its address', function (): void {
    $this->plugin(PluginFixtures::path('contributor'))->enable();
    $outbound = $this->outbound();

    $item = $this->publish('Hello world');

    expect($item)->toBeInstanceOf(ItemRef::class)
        ->and($item->status)->toBe('published')
        ->and($item->url)->toEndWith('/hello-world/');

    $outbound->assertSent(fn (SentRequest $sent): bool => $sent->url === 'https://api.example.com/published' && $sent->json()['id'] === $item->id);
});

it('writes a draft with no address', function (): void {
    $item = $this->draft('Not yet', 'page');

    expect($item->type)->toBe('page')
        ->and($item->status)->toBe('draft')
        ->and($item->url)->toBeNull();
});

it('unpublishes and trashes an item, which then has no address', function (): void {
    $item = $this->publish('Short lived');

    expect($this->unpublish($item)->url)->toBeNull()
        ->and($this->trash($item)->url)->toBeNull()
        ->and(Content::withTrashed()->findOrFail($item->id)->trashed())->toBeTrue();
});

it('refuses a contributor who publishes, as the admin would', function (): void {
    $contributor = $this->person('contributor');
    $item = $this->draft('Mine', by: $contributor);

    expect(fn () => $this->publish($item, by: $contributor))->toThrow(AssertionFailedError::class, 'Publishing "Mine" was refused');
});
