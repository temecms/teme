<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Hooks\HookDispatcher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Cache\PagesPurged;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

/**
 * Records each PagesPurged the dispatcher delivers, with the open transaction
 * depth when a listener runs.
 *
 * @return ArrayObject<int, array{payload: PagesPurged, depth: int}>
 */
function pagesPurgedRecord(): ArrayObject
{
    $heard = new ArrayObject;

    app(HookDispatcher::class)->subscriber('core')->on(PagesPurged::class, function (PagesPurged $payload) use ($heard): void {
        $heard[] = ['payload' => $payload, 'depth' => DB::transactionLevel()];
    });

    return $heard;
}

it('sends PagesPurged once per purge, after commit, with the cache tags and the address of a page it removed', function (): void {
    $base = DB::transactionLevel();
    $story = addrPublished($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    cacheWarmOn();
    $heard = pagesPurgedRecord();

    app(TrashContent::class)->execute(new ContentActionInput((string) $story->id, (string) $this->editor->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[0]['payload']->tags)->toContain(PageTags::url('/big-story/'))
        ->and($heard[0]['payload']->addresses)->toContain('http://localhost/big-story/');
});

it('sends PagesPurged once for a change that purges many tags, not once per tag', function (): void {
    $story = addrPublished($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    $heard = pagesPurgedRecord();

    app(TrashContent::class)->execute(new ContentActionInput((string) $story->id, (string) $this->editor->id));

    expect($heard)->toHaveCount(1)
        ->and(count($heard[0]['payload']->tags))->toBeGreaterThan(1);
});

it('sends nothing when the change is rolled back', function (): void {
    $heard = pagesPurgedRecord();

    expect(fn () => cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry', PageTags::url('/ferry-timetable/')]), fails: true)->execute(schedProbeInput()))
        ->toThrow(RuntimeException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

it('sends one PagesPurged when an item is published, with that item’s address', function (): void {
    $story = addrPublished($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    docSave($story, $this->author, docWith(docText('A longer story.')));
    cacheWarmOn();
    $heard = pagesPurgedRecord();

    listsPublish($story, $this->author);

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['payload']->addresses)->toBe(['http://localhost/big-story/'])
        ->and($heard[0]['payload']->tags)->toContain(PageTags::url('/big-story/'));
});

it('sends PagesPurged with no addresses when the cache warms none', function (): void {
    $story = addrPublished($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    config(['page_cache.warm.enabled' => false]);
    $heard = pagesPurgedRecord();

    app(TrashContent::class)->execute(new ContentActionInput((string) $story->id, (string) $this->editor->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]['payload']->addresses)->toBe([])
        ->and($heard[0]['payload']->tags)->toContain(PageTags::url('/big-story/'));
});
