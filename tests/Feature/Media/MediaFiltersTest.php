<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Media\Queries\MediaMonths;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');

    // Uploads a file at this UTC time; each image a different size, so none is a duplicate.
    $this->width = 400;
    $this->at = function (string $when, string $name, bool $document = false) {
        $this->travelTo(CarbonImmutable::parse($when, 'UTC'));

        return mediaUpload($this->author, $document ? mediaPdf() : mediaJpeg($this->width++, 300), $name);
    };
});

function mfTitles(array $arguments = []): array
{
    return array_column(app(MediaLibrary::class)->page(new MediaLibraryRequest(...$arguments))['rows'], 'title');
}

it('narrows either tab by type, and ignores a type Teme doesn’t have', function (): void {
    ($this->at)('2026-10-01 09:00:00', 'harbour.jpg');
    ($this->at)('2026-10-01 09:01:00', 'fares.pdf', document: true);
    $gone = ($this->at)('2026-10-01 09:02:00', 'gone.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));

    expect(mfTitles(['type' => 'image']))->toBe(['harbour'])
        ->and(mfTitles(['type' => 'document']))->toBe(['fares'])
        ->and(mfTitles(['tab' => 'trash', 'type' => 'image']))->toBe(['gone'])
        ->and(mfTitles(['tab' => 'trash', 'type' => 'document']))->toBe([])
        ->and(mfTitles(['type' => 'spreadsheet']))->toBe(['fares', 'harbour'])
        ->and(app(MediaLibrary::class)->page(new MediaLibraryRequest(type: 'spreadsheet'))['filtered'])->toBeFalse();
});

it('narrows by the site’s month, at its edges, and ignores a month that isn’t one', function (): void {
    // Lagos is UTC+1 all year: each upload is given in UTC, with its Lagos time beside it.
    ($this->at)('2026-09-30 22:30:00', 'september.jpg');        // 23:30 on 30 September
    ($this->at)('2026-09-30 23:30:00', 'first-of-october.jpg'); // 00:30 on 1 October
    ($this->at)('2026-10-31 22:59:59', 'last-of-october.jpg');  // 23:59:59 on 31 October
    ($this->at)('2026-10-31 23:00:00', 'november.jpg');         // midnight, 1 November
    $lagos = fn (string $month): array => ['month' => $month, 'zone' => 'Africa/Lagos'];

    expect(mfTitles($lagos('2026-10')))->toBe(['last-of-october', 'first-of-october'])
        ->and(mfTitles($lagos('2026-09')))->toBe(['september'])
        // The same month on a UTC clock holds a different pair.
        ->and(mfTitles(['month' => '2026-10']))->toBe(['november', 'last-of-october'])
        ->and(mfTitles($lagos('2026-13')))->toHaveCount(4)
        ->and((new MediaLibraryRequest(month: '2026-10', zone: 'Africa/Lagos'))->monthBounds())->toBe(['2026-09-30T23:00:00+00:00', '2026-10-31T23:00:00+00:00'])
        ->and((new MediaLibraryRequest(month: 'October', zone: 'Africa/Lagos'))->monthBounds())->toBeNull();
});

it('lists the months that hold files on the site’s clock, newest first, the trash’s on its own, and a chosen month it lacks', function (): void {
    ($this->at)('2026-07-15 10:00:00', 'july.jpg');
    // 23:30 UTC on the last day of September: 00:30 on 1 October in Lagos.
    ($this->at)('2026-09-30 23:30:00', 'late-september.jpg');
    ($this->at)('2026-10-02 10:00:00', 'october.jpg');
    $gone = ($this->at)('2026-08-02 10:00:00', 'august.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));

    $months = app(MediaMonths::class);

    // August's only file is in the trash, and no file was uploaded in June.
    expect($months->list(false, 'UTC'))->toBe([
        ['value' => '2026-10', 'label' => 'October 2026'],
        ['value' => '2026-09', 'label' => 'September 2026'],
        ['value' => '2026-07', 'label' => 'July 2026'],
    ])
        ->and(array_column($months->list(false, 'Africa/Lagos'), 'value'))->toBe(['2026-10', '2026-07'])
        ->and($months->list(true, 'Africa/Lagos'))->toBe([['value' => '2026-08', 'label' => 'August 2026']])
        ->and(array_column($months->list(false, 'UTC', '2025-01'), 'value'))->toBe(['2026-10', '2026-09', '2026-07', '2025-01'])
        ->and(array_column($months->list(false, 'UTC', '2026-09'), 'value'))->toBe(['2026-10', '2026-09', '2026-07'])
        ->and(array_column($months->list(false, 'UTC', 'nonsense'), 'value'))->toBe(['2026-10', '2026-09', '2026-07']);
});

it('finds each month that holds a live file by probing the index, never by reading the library', function (): void {
    ($this->at)('2026-07-15 10:00:00', 'july.jpg');
    ($this->at)('2026-10-01 00:00:00', 'october.jpg');
    $gone = ($this->at)('2026-08-02 10:00:00', 'august.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));

    $plan = planOf(fn () => app(MediaMonths::class)->list(false, 'Africa/Lagos'), 'WITH RECURSIVE months');

    expect($plan)->toContain('media_live_created')
        ->and($plan)->toContain('Limit')
        ->and($plan)->not->toContain('Seq Scan');
});

it('carries each file’s alt text, credit and licence on its row, in the library and the modal alike', function (): void {
    $described = ($this->at)('2026-10-01 09:00:00', 'harbour.jpg');
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($described->id, (string) $this->author->id, 'Harbour', 'Boats at dawn', null, 'Photo: Kemi Adeyemi', 50, 50, 'Staff photo'));
    $this->travelBack();

    $row = app(MediaLibrary::class)->page(new MediaLibraryRequest)['rows'][0];
    $picked = $this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image']]))->assertOk()->json('rows.0');

    expect([$row['alt'], $row['credit'], $row['licence']])->toBe(['Boats at dawn', 'Photo: Kemi Adeyemi', 'Staff photo'])
        ->and([$picked['alt'], $picked['credit'], $picked['licence']])->toBe(['Boats at dawn', 'Photo: Kemi Adeyemi', 'Staff photo']);
});

it('sends the Media screen the filters it was given, and the site’s months for its tab', function (): void {
    lscrTimezone('Africa/Lagos');
    // 23:30 UTC on the last day of September: October's file on a Lagos clock.
    ($this->at)('2026-09-30 23:30:00', 'harbour.jpg');
    $gone = ($this->at)('2026-08-02 10:00:00', 'august.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));
    $this->travelBack();

    $this->actingAs($this->author)->get(route('media.index', ['type' => 'image', 'month' => '2026-10', 'from' => '2026-01-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/index')
            ->where('filters', ['type' => 'image', 'month' => '2026-10'])
            ->where('months', [['value' => '2026-10', 'label' => 'October 2026']])
            ->where('siteTimezone', 'Africa/Lagos')
            ->where('rows.0.title', 'harbour')
            ->where('tabs', [['key' => 'all', 'label' => 'All', 'count' => 1], ['key' => 'trash', 'label' => 'Trash', 'count' => 1]]));

    $this->actingAs($this->author)->get(route('media.index', ['tab' => 'trash']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('months', [['value' => '2026-08', 'label' => 'August 2026']]));
});

it('sends the media modal the live library’s months on the site’s clock, and narrows it by one', function (): void {
    lscrTimezone('Africa/Lagos');
    ($this->at)('2026-09-30 22:30:00', 'september.jpg'); // 23:30 on 30 September in Lagos
    ($this->at)('2026-09-30 23:30:00', 'october.jpg');   // 00:30 on 1 October in Lagos
    $this->travelBack();

    $this->actingAs($this->author)->getJson(route('media.picker', ['kinds' => ['image'], 'month' => '2026-09']))
        ->assertOk()
        ->assertJsonPath('months', [['value' => '2026-10', 'label' => 'October 2026'], ['value' => '2026-09', 'label' => 'September 2026']])
        ->assertJsonPath('siteTimezone', 'Africa/Lagos')
        ->assertJsonPath('rows.0.title', 'september')
        ->assertJsonCount(1, 'rows');
});

it('narrows to imported files, and counts each chip’s files in the live library', function (): void {
    $quay = ($this->at)('2026-10-01 09:00:00', 'quay.jpg');
    ($this->at)('2026-10-01 09:01:00', 'fares.pdf', document: true);
    $gone = ($this->at)('2026-10-01 09:02:00', 'gone.jpg');
    DB::table('media')->whereIn('id', [$quay->id, $gone->id])->update(['source_url' => 'https://images.example.org/quay.jpg']);
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->author->id));

    expect(mfTitles(['imported' => true]))->toBe(['quay'])
        ->and(app(MediaLibrary::class)->page(new MediaLibraryRequest(imported: true))['filtered'])->toBeTrue()
        ->and(app(MediaLibrary::class)->chips())->toBe(['missingAlt' => 1, 'unused' => 2, 'imported' => 1]);

    $this->actingAs($this->author)->get(route('media.index', ['imported' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.imported', '1')
            ->where('chips', ['missingAlt' => 1, 'unused' => 2, 'imported' => 1])
            ->where('rows.0.title', 'quay'));
});
