<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemCard;
use Teme\Contracts\Content\ReadRefused;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('librarian');
    config(['filesystems.disks.media.url' => 'https://harbour.example/storage/media']);

    $this->reader = fn (): ContentReader => app(PluginDoors::class)
        ->for('tests/librarian', 'librarian', ['content.read'], [])[ContentReader::class];
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('gives a published item’s excerpt, featured image address and the sizes that exist', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $image = pageImage($author, 1200, 800);
    $item = pageArticle($author, 'Harbour dawn', excerpt: 'Morning light on the water.', featured: $image);

    $card = ($this->reader)()->card($item->id);

    expect($card)->toBeInstanceOf(ItemCard::class)
        ->and($card->item->id)->toBe($item->id)
        ->and($card->item->title)->toBe('Harbour dawn')
        ->and($card->item->status)->toBe('published')
        ->and($card->excerpt)->toBe('Morning light on the water.')
        ->and($card->featuredImage)->not->toBeNull()
        ->and($card->featuredImage->id)->toBe((string) $image->id)
        ->and($card->featuredImage->address)->toStartWith('https://harbour.example/')
        ->and($card->featuredImage->sizes)->not->toBe([])
        ->and($card->featuredImage->sizes[0]->name)->toBeString()
        ->and($card->featuredImage->sizes[0]->width)->toBeInt()
        ->and($card->featuredImage->sizes[0]->height)->toBeInt()
        ->and($card->featuredImage->sizes[0]->address)->toStartWith('https://harbour.example/');
});

it('gives a published item with no excerpt or featured image nulls for both', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $item = pageArticle($author, 'Bare harbour');

    $card = ($this->reader)()->card($item->id);

    expect($card->excerpt)->toBeNull()
        ->and($card->featuredImage)->toBeNull();
});

it('builds the featured image address from the current media address', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $image = pageImage($author, 800, 600);
    $item = pageArticle($author, 'Moved harbour', featured: $image);

    config(['filesystems.disks.media.url' => 'https://one.example/storage/media']);
    Storage::forgetDisk('media');
    app()->forgetScopedInstances();
    $before = ($this->reader)()->card($item->id)->featuredImage?->address;
    config(['filesystems.disks.media.url' => 'https://two.example/storage/media']);
    Storage::forgetDisk('media');
    app()->forgetScopedInstances();
    $after = ($this->reader)()->card($item->id)->featuredImage?->address;

    expect($before)->toStartWith('https://one.example/')
        ->and($after)->toStartWith('https://two.example/');
});

it('refuses a draft, a private item, a password-protected item, a scheduled item, a trashed item, a hidden language and a missing one', function (Closure $item, string $words): void {
    $author = User::factory()->create()->assignRole('editor');
    $id = $item($author);

    expect(fn () => ($this->reader)()->card($id))->toThrow(ReadRefused::class, $words);
})->with([
    'a draft' => [
        function (User $author): string {
            return lifecycleDraft($author, 'Not ready')->id;
        },
        'Only a published public item has a card.',
    ],
    'a private item' => [
        function (User $author): string {
            $item = pageArticle($author, 'Private harbour');
            app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, 'private', null, (string) $author->id));

            return $item->id;
        },
        'Only a published public item has a card.',
    ],
    'a password-protected item' => [
        function (User $author): string {
            $item = pageArticle($author, 'Protected harbour');
            app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, 'protected', 'harbour-secret', (string) $author->id));

            return $item->id;
        },
        'Only a published public item has a card.',
    ],
    'a scheduled item' => [
        function (User $author): string {
            $item = lifecycleDraft($author, 'Later ferry');
            schedFor($item, $author, now()->addDay()->toIso8601String());

            return $item->id;
        },
        'Only a published public item has a card.',
    ],
    'a trashed item' => [
        function (User $author): string {
            $item = pageArticle($author, 'Gone harbour');
            pageTrash($item, $author);

            return $item->id;
        },
        'Only a published public item has a card.',
    ],
    'a hidden language' => [
        function (User $author): string {
            Languages::set(['es'], hidden: ['es']);
            $item = pageArticle($author, 'Puerto oculto');
            DB::table('contents')->where('id', $item->id)->update(['language' => 'es']);

            return $item->id;
        },
        'Only a published public item has a card.',
    ],
    'a missing item' => [
        fn (User $author): string => '01jc4r2v9d8f7g6h5j4k3m2n1p',
        'Only a published public item has a card.',
    ],
]);

it('gives a null featured image when the media is in the trash', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $image = pageImage($author, 800, 600);
    $item = pageArticle($author, 'Missing picture', featured: $image);
    app(TrashMedia::class)->execute(new MediaActionInput((string) $image->id, (string) $author->id));

    $card = ($this->reader)()->card($item->id);

    expect($card->featuredImage)->toBeNull()
        ->and($card->item->title)->toBe('Missing picture');
});

it('never needs a person, and never reads a draft’s working fields', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $item = pageArticle($author, 'Published words', excerpt: 'On the quay.');
    docSave($item, $author, docWith(docText('Working')), fields: [
        BuiltInFields::EXCERPT => 'Still a draft excerpt.',
    ], title: 'Draft title only');

    $card = ($this->reader)()->card($item->id);

    expect($card->excerpt)->toBe('On the quay.')
        ->and($card->item->title)->toBe('Published words')
        ->and($card->item->status)->toBe('published');
});
