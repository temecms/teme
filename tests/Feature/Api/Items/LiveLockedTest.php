<?php

declare(strict_types=1);

use App\Domain\Api\ApiQuery;
use App\Domain\Api\Items\ItemFilters;
use App\Domain\Api\Items\ItemQuery;
use App\Domain\Api\Items\ItemsQuery;
use App\Domain\Api\Live;
use App\Http\Api\V1\ItemsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

it('adds whether each live item is behind a password, and keeps the same rows', function (): void {
    $site = DeliverySite::build();

    $locked = Live::withLocked(Live::items()->select('contents.id'))->pluck('locked', 'id')->all();

    expect(array_keys($locked))->toEqualCanonicalizing(Live::items()->pluck('id')->all())
        ->and($locked[$site->protected->id])->toBeTrue()
        ->and($locked[$site->public->id])->toBeFalse()
        ->and(array_keys(array_filter($locked)))->toBe([$site->protected->id]);
});

it('locks an item whose visibility is anything but public, a value a later release adds included', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    // Written past the guard, as a later release's value would be: the column's check knows only today's three.
    DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_visibility_check');
    DB::table('contents')->where('id', $site->public->id)->update(['visibility' => 'members']);

    $locked = Live::withLocked(Live::items()->select('contents.id'))->pluck('locked', 'id')->all();
    $item = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk();

    expect($locked[$site->public->id])->toBeTrue()
        ->and($locked[$site->protected->id])->toBeTrue()
        ->and($locked[$site->sequel->id])->toBeFalse()
        ->and($item->json('data.body'))->toBeNull()
        ->and($item->json('data.fields'))->toBe([])
        ->and($item->json('data.excerpt'))->toBeNull()
        ->and($item->json('data.featured_image'))->toBeNull()
        ->and((string) $item->getContent())->not->toContain('Louise Banks')->not->toContain('Why are they here?');
});

it('never reads a locked item\'s document or fields, one item or a list', function (): void {
    $site = DeliverySite::build();
    $one = app(ItemQuery::class);
    $locked = $one->byId($site->protected->id);
    $open = $one->byId($site->public->id);
    $rows = collect(app(ItemsQuery::class)->page(ItemFilters::from(ApiQuery::from(Request::create('/api/v1/items?limit=100'), ItemsController::PARAMETERS['index'])))['rows'])->keyBy('id');

    expect($locked?->document)->toBeNull()
        ->and($locked?->fields)->toBeNull()
        ->and($open?->document)->toContain('Louise Banks')
        ->and($open?->fields)->toContain('Why are they here?')
        ->and($rows[$site->protected->id]->fields)->toBeNull()
        ->and($rows[$site->public->id]->fields)->toContain('Why are they here?');
});
