<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Images\FocalCrop;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\RecordMediaVariants;
use App\Domain\Media\Operations\RequestMediaProcessing;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    // The editor uploads; the author may use the image, never change it (MediaPolicy).
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->share = ['social', 'crop-16x9', 'crop-4x3', 'crop-1x1'];
});

it('sizes each share image at the largest the original allows in its ratio, never enlarged, and crops as close to centred on the focal point as the edges allow (I-700)', function (): void {
    expect(array_map(fn (array $size): array => MediaVariants::shareSize(800, 600, ...$size), MediaVariants::SHARE))
        ->toBe(['social' => [800, 420], 'crop-16x9' => [800, 450], 'crop-4x3' => [800, 600], 'crop-1x1' => [600, 600]])
        ->and(MediaVariants::shareSize(2400, 1600, 1200, 630))->toBe([1200, 630])
        ->and(FocalCrop::fit(2400, 1600, 1200, 630, 30, 40))->toBe([1200, 800, 0, 5])
        ->and(FocalCrop::square(1000, 500, 300, 50, 50))->toBe(FocalCrop::fit(1000, 500, 300, 300, 50, 50));
});

it('makes an image\'s card and three crops the first time it is used as a featured image, as the author who used it, and the card is what the head then shows (I-855)', function (): void {
    $picture = pageImage($this->editor, 2400, 1600, 30, 40);

    expect($picture->variants)->not->toHaveKey('social');

    headPost($this->author, 'Ferry fares', featured: $picture);
    $variants = $picture->refresh()->variants;
    $dom = headDom('/ferry-fares/');

    expect(array_map(fn (string $key): array => [$variants[$key]['width'], $variants[$key]['height']], $this->share))
        ->toBe([[1200, 630], [1200, 675], [1200, 900], [1200, 1200]])
        ->and($picture->processing)->toBe('ready')
        ->and(headValue($dom, '//head/meta[@property="og:image"]/@content'))->toEndWith($variants['social']['path'])
        ->and([headValue($dom, '//head/meta[@property="og:image:width"]/@content'), headValue($dom, '//head/meta[@property="og:image:height"]/@content')])->toBe(['1200', '630']);

    foreach ($this->share as $key) {
        Storage::disk('media')->assertExists($variants[$key]['path']);
    }
});

it('never makes them for an image used only in a body (I-855)', function (): void {
    $picture = pageImage($this->editor, 1600, 900);

    headPost($this->author, 'Inline only', nodes: [pageImageNode($picture, (string) Str::ulid())]);

    expect(array_intersect(array_keys($picture->refresh()->variants), $this->share))->toBe([]);
});

it('makes them for an image first used as a social image, and queues nothing for one that has them', function (): void {
    $picture = pageImage($this->editor, 1600, 900);
    headPost($this->author, 'Shared', [BuiltInFields::SOCIAL_IMAGE => ['id' => $picture->id, 'alt' => null, 'decorative' => false]]);

    expect($picture->refresh()->variants)->toHaveKey('social');

    Queue::fake();
    headPost($this->author, 'Featured again', featured: $picture);

    Queue::assertNotPushed(GenerateMediaVariants::class);
});

it('keeps them when the variants are remade, and drops them, files and all, once nothing uses the image so', function (): void {
    $picture = pageImage($this->editor, 1600, 900);
    $draft = refsDraft($this->author, refsFeatured($picture));
    $card = $picture->refresh()->variants['social']['path'];

    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($picture->id, (string) $this->editor->id, $picture->title, $picture->alt, $picture->caption, $picture->credit, 70, 20));

    expect($picture->refresh()->variants)->toHaveKey('social');

    refsSave($draft, $this->author, []);
    app(RequestMediaProcessing::class)->execute(new MediaActionInput($picture->id, (string) $this->editor->id));

    expect(array_intersect(array_keys($picture->refresh()->variants), $this->share))->toBe([]);
    Storage::disk('media')->assertMissing($card);
});

it('lets someone who may edit the item using the image record its variants, and refuses anyone else, writing nothing (I-392)', function (): void {
    $picture = pageImage($this->editor, 1600, 900);
    Queue::fake();
    $draft = refsDraft($this->author, refsFeatured($picture));
    $stranger = User::factory()->create()->assignRole('contributor');
    $before = $picture->refresh()->only(['variants', 'variant_set', 'processing', 'updated_at']);
    $record = fn (User $actor) => app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($picture->id, $picture->file_version, MediaVariants::SET, $picture->variants, null, (string) $actor->id, $draft->id));

    expect(fn () => $record($stranger))->toThrow(AuthorizationException::class)
        ->and($picture->refresh()->only(['variants', 'variant_set', 'processing', 'updated_at']))->toEqual($before)
        ->and(fn () => $record($this->author))->not->toThrow(Throwable::class);
});

it('queues one more remake when variants land without the share images while the image is wanted (I-855)', function (): void {
    $picture = pageImage($this->editor, 1600, 900);
    Queue::fake();
    refsDraft($this->author, refsFeatured($picture));

    Queue::assertPushed(GenerateMediaVariants::class, 1);

    app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($picture->id, $picture->file_version, MediaVariants::SET, $picture->refresh()->variants, null, (string) $this->editor->id));

    Queue::assertPushed(GenerateMediaVariants::class, 2);
});

it('makes them for an image already featured when teme:media:regenerate runs, as a site upgraded with featured images needs', function (): void {
    $picture = pageImage($this->editor, 1600, 900);
    Queue::fake();
    refsDraft($this->author, refsFeatured($picture));

    expect($picture->refresh()->variants)->not->toHaveKey('social');

    $this->artisan('teme:media:regenerate')->assertSuccessful();

    expect($picture->refresh()->variants)->toHaveKeys($this->share);
});
