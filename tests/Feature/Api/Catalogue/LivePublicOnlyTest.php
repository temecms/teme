<?php

declare(strict_types=1);

use App\Domain\Api\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\DeliverySite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
});

it('keeps the live items open to anyone, leaving out the password-protected and private ones', function (): void {
    $site = $this->site;
    $ids = Live::publicOnly(Live::items())->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();

    expect($ids)->toContain($site->public->id, $site->sequel->id, $site->about->id)
        ->and($ids)->not->toContain($site->protected->id)
        ->and($ids)->not->toContain($site->private->id)
        ->and($ids)->not->toContain($site->draft->id)
        ->and(Live::items()->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all())->toContain($site->protected->id);
});

it('narrows a joined query on contents too', function (): void {
    $site = $this->site;
    $joined = fn () => Live::apply(DB::table('media_usages')->join('contents', 'contents.id', '=', 'media_usages.content_id'))->where('media_usages.scope', 'published');

    $all = $joined()->pluck('media_usages.media_id')->map(fn (mixed $id): string => (string) $id)->all();
    $open = Live::publicOnly($joined())->pluck('media_usages.media_id')->map(fn (mixed $id): string => (string) $id)->all();

    expect($all)->toContain($site->protectedOnly->id)
        ->and($open)->not->toContain($site->protectedOnly->id)
        ->and($open)->toContain($site->shell->id);
});
