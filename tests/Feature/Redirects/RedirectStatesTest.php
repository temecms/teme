<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Redirects\Queries\RedirectStates;
use App\Domain\Redirects\RedirectState;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->states = fn (string ...$ids): array => array_map(
        fn (array $state): array => [$state['state'], $state['label']],
        app(RedirectStates::class)->of($ids),
    );
});

it('says what each redirect does now: on, off, or waiting for a page, an old address, its item or its file, or that they are gone (I-857, I-858)', function (): void {
    $live = addrPublished($this->editor, 'Harbour news');
    $moved = addrPublished($this->editor, 'Ferry fares');
    addrRename($moved, 'ferry-fares-2026', $this->editor);
    $hidden = addrPublished($this->editor, 'Market day');
    app(UnpublishContent::class)->execute(new ContentActionInput($hidden->id, (string) $this->editor->id));
    $gone = addrPublished($this->editor, 'Old story');
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');
    $lostFile = mediaUpload($this->editor, mediaPdf(), 'Old timetable.pdf', allowDuplicate: true);

    $ids = [
        'on' => rdSaved('/old-fares', '/news/'),
        'off' => rdSaved('/switched-off', '/news/', on: false),
        'page' => rdSaved('/harbour-news', '/news/'),
        'old address' => rdSaved('/ferry-fares', '/news/'),
        'date' => rdSaved('/2019/05', '/news/'),
        'item waits' => rdSaved('/market', item: $hidden->id),
        'item gone' => rdSaved('/story', item: $gone->id),
        'file' => rdSaved('/timetable', file: $file->id),
        'file waits' => rdSaved('/old-timetable', file: $lostFile->id),
        'gone' => rdSaved('/withdrawn', status: 410),
    ];

    pageTrash($gone, $this->editor);
    app(DeleteContentPermanently::class)->execute(new ContentActionInput($gone->id, (string) $this->editor->id));
    app(TrashMedia::class)->execute(new MediaActionInput($lostFile->id, (string) $this->editor->id));

    expect(array_map(fn (string $id): array => ($this->states)($id)[$id], $ids))->toBe([
        'on' => [RedirectState::On, 'On'],
        'off' => [RedirectState::Off, 'Off'],
        'page' => [RedirectState::WaitingForPage, 'Waiting: a page answers this address'],
        'old address' => [RedirectState::WaitingForOldAddress, 'Waiting: this old address already sends readers on'],
        'date' => [RedirectState::WaitingForPage, 'Waiting: a page answers this address'],
        'item waits' => [RedirectState::WaitingForItem, 'Waiting: the post isn’t published'],
        'item gone' => [RedirectState::ItemDeleted, 'Its item was deleted'],
        'file' => [RedirectState::On, 'On'],
        'file waits' => [RedirectState::WaitingForFile, 'Waiting: the file is in the trash or not ready yet'],
        'gone' => [RedirectState::On, 'On'],
    ])->and(($this->states)('01jvm0000000000000000000aa'))->toBe([]);

    // A live page only ever makes an exact redirect wait (I-858); the live item it was is unchanged.
    expect($live->refresh()->status)->toBe('published');
});

it('reads the same few statements for a page of redirects, however many', function (): void {
    $count = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RedirectStates::class)->of($ids);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $file = mediaUpload($this->editor, mediaPdf(), 'Timetable.pdf');
    $item = addrPublished($this->editor, 'Harbour news');
    $two = [rdSaved('/a', file: $file->id), rdSaved('/b', item: $item->id)];
    $six = [...$two, rdSaved('/c', file: $file->id), rdSaved('/d', item: $item->id), rdSaved('/e', '/news/'), rdSaved('/f', status: 410)];

    expect($count($six))->toBe($count($two))->toBeLessThanOrEqual(4);
});

it('lets a redirect answer an address of a kind this release does not know, and says it is on', function (string $kind): void {
    // A kind a later release adds, or an author's row, with a ULID: this release finds nothing there.
    $id = '01kv7m3q9x2c5r8t1w4y6z0b3d';
    DB::table('paths')->insert([
        ['path' => 'people/jane', 'target_type' => $kind, 'target_id' => $id, 'is_current' => true, 'created_at' => now(), 'replaced_at' => null],
        ['path' => 'people/old-jane', 'target_type' => $kind, 'target_id' => $id, 'is_current' => false, 'created_at' => now(), 'replaced_at' => now()],
    ]);
    $current = rdSaved('/people/jane', '/news/');
    $old = rdSaved('/people/old-jane', '/news/');

    expect(($this->states)($current, $old))->toBe([
        $current => [RedirectState::On, 'On'],
        $old => [RedirectState::On, 'On'],
    ])
        ->and(addrLocation(addrGet('/people/jane/')->assertStatus(301)))->toBe('/news/')
        ->and(addrLocation(addrGet('/people/old-jane/')->assertStatus(301)))->toBe('/news/');
})->with(['gadget', 'user']);

it('waits at an author’s address and at an author’s old one', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    DB::table('paths')->insert([
        ['path' => 'author/jane-doe', 'target_type' => 'user', 'target_id' => (string) $jane->id, 'is_current' => true, 'created_at' => now(), 'replaced_at' => null],
        ['path' => 'writers/jane-doe', 'target_type' => 'user', 'target_id' => (string) $jane->id, 'is_current' => false, 'created_at' => now(), 'replaced_at' => now()],
    ]);
    $current = rdSaved('/author/jane-doe', '/news/');
    $old = rdSaved('/writers/jane-doe', '/news/');

    expect(($this->states)($current, $old))->toBe([
        $current => [RedirectState::WaitingForPage, 'Waiting: a page answers this address'],
        $old => [RedirectState::WaitingForOldAddress, 'Waiting: this old address already sends readers on'],
    ]);
});
