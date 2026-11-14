<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // A group of this test's own, as a plugin would declare one.
    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'test_publisher',
        label: 'Test publisher',
        description: 'A group for this test.',
        section: 'test_publisher',
        ability: 'settings.general.manage',
        settings: [
            Setting::urls('links', 'Links', 'Addresses for this test.', '0.1.0')->max(3),
            Setting::person('someone', 'Someone', 'A person for this test.', '0.1.0'),
            Setting::hours('week', 'Week', 'Opening hours for this test.', '0.1.0'),
        ],
        since: '0.1.0',
    ));

    $this->save = fn (array $values, int $version = 0) => $this->actingAs($this->admin)->from('/admin/settings/test_publisher')
        ->put('/admin/settings/test_publisher', ['values' => $values, 'expected_version' => $version]);
    $this->stored = fn (): array => (array) json_decode((string) (DB::table('settings')->where('group_key', 'test_publisher')->value('overrides') ?? '{}'), true);
    $this->read = fn (string $key): mixed => app(SettingsReader::class)->get('test_publisher', $key);
});

it('stores addresses one a line, trimmed, in their order, blank lines and repeats dropped, and nothing once cleared', function (): void {
    ($this->save)(['links' => " https://x.com/harbourledger \r\n\r\nhttps://www.wikidata.org/wiki/Q42\nhttps://x.com/harbourledger"])->assertSessionHasNoErrors();

    expect(($this->read)('links'))->toBe(['https://x.com/harbourledger', 'https://www.wikidata.org/wiki/Q42']);

    ($this->save)(['links' => ''], 1)->assertSessionHasNoErrors();

    expect(($this->read)('links'))->toBe([])
        ->and(($this->stored)())->toBe([]);
});

it('refuses a line that isn\'t a full address, one on this site, and too many, naming the line, and keeps nothing', function (string $links, string $message): void {
    ($this->save)(['links' => $links])->assertSessionHasErrors(['values.links' => $message]);

    expect(($this->stored)())->toBe([]);
})->with([
    'words' => ["https://x.com/harbourledger\nThe Harbour Ledger", 'Line 2 isn’t a full address. Start it with https://.'],
    'another scheme' => ['ftp://files.harbourledger.example/', 'Line 1 isn’t a full address. Start it with https://.'],
    'this site' => ["https://x.com/harbourledger\n\nhttp://localhost/about-us/", 'Line 3 is on this site. Teme writes the site’s own addresses itself, so they stay right if it moves.'],
    'too many' => ["https://a.example/\nhttps://b.example/\nhttps://c.example/\nhttps://d.example/", 'At most 3 addresses.'],
]);

it('stores a person by their ID and nobody once cleared, and refuses someone without an account', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');

    ($this->save)(['someone' => (string) $jane->id])->assertSessionHasNoErrors();

    expect(($this->read)('someone'))->toBe((string) $jane->id);

    ($this->save)(['someone' => '999999'], 1)->assertSessionHasErrors(['values.someone' => 'Choose someone with an account here.']);
    ($this->save)(['someone' => 'jane'], 1)->assertSessionHasErrors(['values.someone' => 'Choose someone with an account here.']);
    ($this->save)(['someone' => ''], 1)->assertSessionHasNoErrors();

    expect(($this->read)('someone'))->toBe('');
});

it('offers the people who may write, by name, and keeps the chosen person among them while they have an account', function (): void {
    $zainab = User::factory()->create(['name' => 'Zainab Bello'])->assignRole('author');
    $kemi = User::factory()->create(['name' => 'Kemi Ade'])->assignRole('author');
    $kemi->forceFill(['suspended_at' => now()])->save();
    cmSettings(['someone' => (string) $kemi->id], 'test_publisher');

    $this->actingAs($this->admin)->get('/admin/settings/test_publisher')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('fields.0.type', 'urls')
            ->where('fields.1.type', 'person')
            ->where('fields.1.people', [
                ['value' => (string) $this->admin->id, 'label' => 'Ada Admin'],
                ['value' => (string) $zainab->id, 'label' => 'Zainab Bello'],
                ['value' => (string) $kemi->id, 'label' => 'Kemi Ade'],
            ])
            ->where('fields.2.type', 'hours'));
});

it('stores the week Monday first, each day closed or with its openings, empty openings dropped, closed over any times, and nothing for an empty week (I-877)', function (): void {
    ($this->save)(['week' => [
        'sunday' => ['closed' => '1', 'hours' => [['opens' => '10:00', 'closes' => '16:00']]],
        'monday' => ['hours' => [['opens' => '09:00', 'closes' => '12:30'], ['opens' => '14:00', 'closes' => '18:00']]],
        'tuesday' => ['hours' => [['opens' => '', 'closes' => ''], ['opens' => '09:00', 'closes' => '17:00']]],
        'wednesday' => ['hours' => [['opens' => '', 'closes' => '']]],
        'friday' => ['hours' => [['opens' => '18:00', 'closes' => '02:00']]],
    ]])->assertSessionHasNoErrors();

    expect(($this->read)('week'))->toEqual([
        ['day' => 'monday', 'hours' => [['opens' => '09:00', 'closes' => '12:30'], ['opens' => '14:00', 'closes' => '18:00']], 'closed' => false],
        ['day' => 'tuesday', 'hours' => [['opens' => '09:00', 'closes' => '17:00']], 'closed' => false],
        ['day' => 'friday', 'hours' => [['opens' => '18:00', 'closes' => '02:00']], 'closed' => false],
        ['day' => 'sunday', 'hours' => [], 'closed' => true],
    ]);

    ($this->save)(['week' => ['monday' => ['hours' => [['opens' => '', 'closes' => '']]]]], 1)->assertSessionHasNoErrors();

    expect(($this->read)('week'))->toBe([])
        ->and(($this->stored)())->toBe([]);
});

it('saves the same week again as no change, since the stored week reads back as it was written', function (): void {
    $week = ['monday' => ['hours' => [['opens' => '09:00', 'closes' => '17:00'], ['opens' => '18:00', 'closes' => '22:00']]], 'sunday' => ['closed' => '1']];

    ($this->save)(['week' => $week])->assertSessionHasNoErrors();
    ($this->save)(['week' => $week], 1)->assertSessionHasNoErrors();

    expect(DB::table('settings')->where('group_key', 'test_publisher')->value('version'))->toBe(1);
});

it('refuses a week that isn\'t one, naming the day, and keeps nothing (I-877)', function (array $week, string $message): void {
    ($this->save)(['week' => $week])->assertSessionHasErrors(['values.week' => $message]);

    expect(($this->stored)())->toBe([]);
})->with([
    'a day that isn\'t one' => [['funday' => ['closed' => '1']], 'Send the week as days, each with its openings or closed.'],
    'three openings' => [['monday' => ['hours' => [['opens' => '07:00', 'closes' => '09:00'], ['opens' => '11:00', 'closes' => '14:00'], ['opens' => '18:00', 'closes' => '22:00']]]], 'Monday: two openings a day at most.'],
    'an opening with one time' => [['tuesday' => ['hours' => [['opens' => '09:00', 'closes' => '']]]], 'Tuesday: give each opening both its times.'],
    'a time that isn\'t one' => [['wednesday' => ['hours' => [['opens' => '9am', 'closes' => '17:00']]]], 'Wednesday: write each time as hours and minutes in 24-hour time, such as 09:00 or 17:30.'],
    'an opening that closes as it opens' => [['thursday' => ['hours' => [['opens' => '09:00', 'closes' => '09:00']]]], 'Thursday: an opening can’t close the minute it opens.'],
    'a second opening before the first closes' => [['friday' => ['hours' => [['opens' => '09:00', 'closes' => '13:00'], ['opens' => '12:00', 'closes' => '18:00']]]], 'Friday: the second opening must start once the first has closed.'],
    'a second opening after one past midnight' => [['saturday' => ['hours' => [['opens' => '18:00', 'closes' => '01:00'], ['opens' => '02:00', 'closes' => '04:00']]]], 'Saturday: the second opening must start once the first has closed.'],
]);
