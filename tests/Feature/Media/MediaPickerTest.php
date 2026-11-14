<?php

declare(strict_types=1);

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
    $this->author = User::factory()->create()->assignRole('author');
});

it('offers ready images, newest first, with their thumbnails, to anyone who writes', function (): void {
    $older = mediaUpload($this->author, mediaJpeg(400, 300), 'older.jpg');
    $newer = mediaUpload($this->author, mediaJpeg(401, 300), 'newer.jpg');
    mediaUpload($this->author, mediaPdf(), 'report.pdf');
    $contributor = User::factory()->create()->assignRole('contributor');

    $rows = $this->actingAs($contributor)->getJson(route('media.picker', ['kinds' => ['image']]))->assertOk()->json('rows');

    expect(array_column($rows, 'id'))->toBe([$newer->id, $older->id])
        ->and($rows[0]['thumbUrl'])->toContain('/storage/');
});

it('searches by file name, title and alt text', function (): void {
    mediaUpload($this->author, mediaJpeg(400, 300), 'harbour-dawn.jpg');
    mediaUpload($this->author, mediaJpeg(401, 300), 'market.jpg');

    $rows = $this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image'], 'search' => 'HARBOUR']))->json('rows');

    expect(array_column($rows, 'title'))->toBe(['harbour-dawn']);
});

it('filters the picker by kind, month, missing alt text and search, and pages by keyset', function (): void {
    $this->travel(-40)->days();
    $old = mediaUpload($this->author, mediaJpeg(400, 300), 'old-month.jpg');
    DB::table('media')->where('id', $old->id)->update(['alt' => 'An old photo']);
    $this->travel(40)->days();
    $described = mediaUpload($this->author, mediaJpeg(401, 300), 'harbour-dawn.jpg');
    DB::table('media')->where('id', $described->id)->update(['alt' => 'Boats at dawn']);
    $this->travel(1)->minutes();
    $undescribed = mediaUpload($this->author, mediaJpeg(402, 300), 'market.jpg');
    $this->travel(1)->minutes();
    mediaUpload($this->author, mediaPdf(), 'report.pdf');

    $thisMonth = now()->format('Y-m');

    expect(array_column($this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image'], 'month' => $thisMonth]))->json('rows'), 'title'))
        ->toBe(['market', 'harbour-dawn'])
        ->and(array_column($this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image'], 'missingAlt' => '1']))->json('rows'), 'id'))
        ->toBe([$undescribed->id])
        ->and(array_column($this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['document']]))->json('rows'), 'title'))
        ->toBe(['report']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image']]))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($queries)->not->toContain('offset');
});

it('never offers a trashed or unfinished item', function (): void {
    $trashed = mediaUpload($this->author, mediaJpeg(), 'gone.jpg');
    DB::table('media')->where('id', $trashed->id)->update(['deleted_at' => now()]);
    $pending = mediaUpload($this->author, mediaJpeg(400, 300), 'pending.jpg');
    DB::table('media')->where('id', $pending->id)->update(['processing' => 'pending']);
    mediaUpload($this->author, mediaJpeg(401, 300), 'ready.jpg');

    $rows = $this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image']]))->json('rows');

    expect(array_column($rows, 'title'))->toBe(['ready']);
});
