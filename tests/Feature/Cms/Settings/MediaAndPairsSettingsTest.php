<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');

    // A group of this test's own, as a plugin would declare one.
    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'test_controls',
        label: 'Test controls',
        description: 'A group for this test.',
        section: 'test_controls',
        ability: 'settings.general.manage',
        settings: [
            Setting::media('picture', 'Picture', 'An image for this test.', '0.1.0'),
            Setting::pairs('tags', 'Tags', 'Pairs for this test.', '0.1.0', 'Name', 'Content')->max(3)->maxLength(10),
        ],
        since: '0.1.0',
    ));

    $this->save = fn (array $values, int $version = 0) => $this->actingAs($this->admin)->from('/admin/settings/test_controls')
        ->put('/admin/settings/test_controls', ['values' => $values, 'expected_version' => $version]);
    $this->stored = fn (): array => (array) json_decode((string) (DB::table('settings')->where('group_key', 'test_controls')->value('overrides') ?? '{}'), true);
    $this->read = fn (string $key): mixed => app(SettingsReader::class)->get('test_controls', $key);
    $this->trash = fn (Media $media) => app(TrashMedia::class)->execute(new MediaActionInput($media->id, (string) $this->admin->id));
});

it('stores the image chosen by its ID, and nothing once it is cleared (I-418)', function (): void {
    $picture = pageImage($this->admin, 1200, 630);

    ($this->save)(['picture' => $picture->id])->assertSessionHasNoErrors();

    expect(($this->read)('picture'))->toBe($picture->id);

    // The control's hidden input, emptied: the browser sends ''.
    ($this->save)(['picture' => ''], 1)->assertSessionHasNoErrors();

    expect(($this->read)('picture'))->toBe('')
        ->and(($this->stored)())->toBe([]);
});

it('refuses anything but a live image from the library, keeping the one chosen', function (string $case): void {
    $kept = pageImage($this->admin, 1200, 630);
    cmSettings(['picture' => $kept->id], 'test_controls');

    $other = match ($case) {
        'a PDF' => mediaUpload($this->admin, mediaPdf(), 'notes.pdf')->id,
        'an image in the trash' => tap(pageImage($this->admin, 800, 600), $this->trash)->id,
        default => '01K5Z0F1ED00000000000000ZZ',
    };

    ($this->save)(['picture' => $other])->assertSessionHasErrors(['values.picture' => 'Choose an image from the library.']);

    expect(($this->read)('picture'))->toBe($kept->id);
})->with(['a PDF', 'an image in the trash', 'an ID nothing has']);

it('stores pairs trimmed, in their order, a row with both parts empty dropped, and an empty list once every row is gone', function (): void {
    ($this->save)(['tags' => [
        ['name' => ' google-site-verification ', 'value' => ' abc '],
        ['name' => '', 'value' => ''],
        ['name' => 'msvalidate.01', 'value' => 'DEF'],
    ]])->assertSessionHasNoErrors();

    expect(($this->read)('tags'))->toEqual([
        ['name' => 'google-site-verification', 'value' => 'abc'],
        ['name' => 'msvalidate.01', 'value' => 'DEF'],
    ]);

    // A form with no rows sends the control's one hidden input, empty.
    ($this->save)(['tags' => ''], 1)->assertSessionHasNoErrors();

    expect(($this->read)('tags'))->toBe([])
        ->and(($this->stored)())->toBe([]);
});

it('refuses pairs that aren\'t a list of rows, too many rows, or a part too long, naming the row, and keeps nothing', function (string $case, string $message): void {
    $tags = match ($case) {
        'not a list' => 'google-site-verification',
        'a row of another shape' => [['name' => 'a', 'other' => 'b']],
        'too many rows' => array_fill(0, 4, ['name' => 'a', 'value' => 'b']),
        'a value too long' => [['name' => 'a', 'value' => 'b'], ['name' => 'c', 'value' => str_repeat('d', 11)]],
        default => [['name' => str_repeat('n', 101), 'value' => 'b']],
    };

    ($this->save)(['tags' => $tags])->assertSessionHasErrors(['values.tags' => $message]);

    expect(($this->stored)())->toBe([]);
})->with([
    'not a list' => ['not a list', 'Send a list of rows, each with a name and a value.'],
    'a row of another shape' => ['a row of another shape', 'Send a list of rows, each with a name and a value.'],
    'too many rows' => ['too many rows', 'At most 3 rows.'],
    'a value too long' => ['a value too long', 'Row 2: the content is longer than 10 characters.'],
    'a name too long' => ['a name too long', 'Row 1: the name is longer than 100 characters.'],
]);

it('sends each media setting\'s chosen image to the form as the editor\'s cards get it, and null once it is gone', function (): void {
    $picture = pageImage($this->admin, 1200, 630);
    cmSettings(['picture' => $picture->id], 'test_controls');

    $this->actingAs($this->admin)->get('/admin/settings/test_controls')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('fields.0.type', 'media')
            ->where('fields.1.type', 'pairs')
            ->where('fields.1.options', ['name' => 'Name', 'value' => 'Content'])
            ->where('fields.1.max', 3)
            ->where('values.picture', $picture->id)
            ->where('media.picture.title', $picture->title)
            ->has('media.picture.variants'));

    ($this->trash)($picture);
    // A new request, as on a real server: CardMedia is scoped.
    app()->forgetScopedInstances();

    $this->actingAs($this->admin)->get('/admin/settings/test_controls')
        ->assertInertia(fn (Assert $page) => $page->where('values.picture', $picture->id)->where('media.picture', null));
});
