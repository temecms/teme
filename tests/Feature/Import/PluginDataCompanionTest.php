<?php

declare(strict_types=1);

use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->companion = importFake();
    $this->companion->items('probe', ['name' => 'teme-companion', 'version' => '0.3.0', 'api' => 2, 'application_passwords' => true]);
    $this->companion->wordpress(
        options: ['importer_mode' => 's:6:"closed";', 'importer_list' => 'a:2:{i:0;s:1:"a";i:1;s:1:"b";}', 'importer_object' => 'O:8:"stdClass":1:{s:1:"a";i:1;}', 'wp_user_roles' => 'a:0:{}'],
        posts: [
            ['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => ['_importer_inbox' => ['https://example.com/inbox'], '_secret' => ['no']]],
            ['id' => 5, 'type' => 'post', 'title' => 'Not an actor', 'meta' => []],
            ['id' => 9, 'type' => 'ap_actor', 'title' => 'Grace', 'meta' => ['_importer_inbox' => ['O:8:"stdClass":0:{}']]],
        ],
        users: [1 => ['importer_also_known_as' => ['a:1:{i:0;s:4:"@ada";}'], 'user_pass' => ['$P$hash']], 2 => ['nickname' => ['grace']]],
        terms: [['id' => 4, 'taxonomy' => 'category', 'meta' => ['importer_colour' => ['red']]]],
    );
    $this->reader = fn (): SourceReader => app(CompanionClients::class)->for(importSource());
});

it('answers declared options decoded, an object as null and flagged, and one WordPress lacks as null', function (): void {
    expect(($this->reader)()->whole('extension', ['kind' => 'options', 'names' => 'importer_mode,importer_list,importer_object,importer_missing']))->toBe([
        'options' => ['importer_mode' => 'closed', 'importer_list' => ['a', 'b'], 'importer_object' => null, 'importer_missing' => null],
        'objects' => ['importer_object'],
    ]);
});

it('answers a keyset page of one post type with only the meta asked for', function (): void {
    $page = ($this->reader)()->page('extension', ['kind' => 'posts', 'type' => 'ap_actor', 'meta' => '_importer_inbox'], 0, 1);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]['id'])->toBe(3)
        ->and($page->items[0]['meta'])->toBe(['_importer_inbox' => ['https://example.com/inbox']])
        ->and($page->nextAfterId)->toBe(3)
        ->and(($this->reader)()->page('extension', ['kind' => 'posts', 'type' => 'ap_actor', 'meta' => '_importer_inbox'], 3, 200)->items[0])
        ->toMatchArray(['id' => 9, 'meta' => ['_importer_inbox' => [null]], 'objects' => ['_importer_inbox']]);
});

it('answers people’s and terms’ declared meta, only for those that have it', function (): void {
    expect(($this->reader)()->page('extension', ['kind' => 'usermeta', 'keys' => 'importer_also_known_as'], 0, 500)->items)
        ->toBe([['id' => 1, 'meta' => ['importer_also_known_as' => [['@ada']]]]])
        ->and(($this->reader)()->page('extension', ['kind' => 'termmeta', 'keys' => 'importer_colour'], 0, 500)->items)
        ->toBe([['id' => 4, 'taxonomy' => 'category', 'meta' => ['importer_colour' => ['red']]]]);
});

it('refuses a never-sent key, whatever is asked', function (array $query): void {
    expect(fn () => ($this->reader)()->whole('extension', $query))->toThrow(SourceRefused::class);
})->with([
    'an option' => [['kind' => 'options', 'names' => 'importer_mode,wp_user_roles']],
    'a person’s meta' => [['kind' => 'usermeta', 'keys' => 'user_pass', 'after_id' => 0, 'limit' => 500]],
    'a post’s meta' => [['kind' => 'posts', 'type' => 'ap_actor', 'meta' => '_application_passwords', 'after_id' => 0, 'limit' => 200]],
    'a name in capitals' => [['kind' => 'usermeta', 'keys' => 'User_Pass', 'after_id' => 0, 'limit' => 500]],
    'the roles option in capitals' => [['kind' => 'options', 'names' => 'WP_USER_ROLES']],
    'the mail server’s password' => [['kind' => 'options', 'names' => 'mailserver_pass']],
    'a person’s capabilities' => [['kind' => 'usermeta', 'keys' => 'wp_capabilities', 'after_id' => 0, 'limit' => 500]],
]);

it('isn’t there on a companion of API 1', function (): void {
    $this->companion->items('probe', ['name' => 'teme-companion', 'version' => '0.2.0', 'api' => 1, 'application_passwords' => true]);

    expect(fn () => ($this->reader)()->whole('extension', ['kind' => 'options', 'names' => 'importer_mode']))->toThrow(SourceRefused::class);
});
