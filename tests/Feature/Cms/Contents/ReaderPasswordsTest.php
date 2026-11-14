<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\ReaderPasswords;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->protect = function (Content $item, string $sealed): Content {
        DB::table('contents')->where('id', $item->id)->update(['visibility' => 'protected', 'reader_password' => $sealed]);

        return Content::query()->findOrFail($item->id);
    };
    $this->item = ($this->protect)(cmItem($this->editor, 'Members'), ReaderPasswords::seal('Harbour 2024'));
});

it('seals a password so the column never holds it as typed, and opens it as typed (I-1700)', function (): void {
    expect(DB::table('contents')->where('id', $this->item->id)->value('reader_password'))->not->toContain('Harbour 2024')
        ->and(ReaderPasswords::open($this->item))->toBe('Harbour 2024');
});

it('opens nothing for an item that isn\'t protected, or whose password another key sealed (I-1700)', function (): void {
    $other = ($this->protect)(cmItem($this->editor, 'Archive'), (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('Harbour 2024'));

    expect(ReaderPasswords::open(cmItem($this->editor, 'Open news')))->toBeNull()
        ->and(ReaderPasswords::open($other))->toBeNull()
        ->and(ReaderPasswords::key($other))->toBeNull()
        ->and(ReaderPasswords::matches($other, 'Harbour 2024'))->toBeFalse();
});

it('takes only the password exactly as typed (I-1700)', function (string $given, bool $right): void {
    expect(ReaderPasswords::matches($this->item, $given))->toBe($right);
})->with([
    'as typed' => ['Harbour 2024', true],
    'another case' => ['harbour 2024', false],
    'a space after' => ['Harbour 2024 ', false],
    'a space before' => [' Harbour 2024', false],
    'shorter' => ['Harbour 202', false],
    'empty' => ['', false],
]);

it('gives a key of 22 characters that changes with the password, the item and the application key (I-1700)', function (): void {
    $key = ReaderPasswords::key($this->item);
    // Every key made under one application key, so one item's can't open another's.
    $otherItem = ReaderPasswords::key(($this->protect)(cmItem($this->editor, 'Archive'), ReaderPasswords::seal('Harbour 2024')));
    $otherPassword = ReaderPasswords::key(($this->protect)($this->item, ReaderPasswords::seal('Harbour 2025')));
    $sameAgain = ReaderPasswords::key(($this->protect)($this->item, ReaderPasswords::seal('Harbour 2024')));

    expect($key)->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and($sameAgain)->toBe($key)
        ->and($otherItem)->not->toBe($key)
        ->and($otherPassword)->not->toBe($key);

    // The encrypter is resolved once; only the key the hash is made with changes.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(ReaderPasswords::key(Content::query()->findOrFail($this->item->id)))->not->toBe($key);
});

it('gives the key for an item only for its right password, and nothing for an item it can\'t open (I-1700)', function (): void {
    expect(ReaderPasswords::keyFor($this->item->id, 'Harbour 2024'))->toBe(ReaderPasswords::key($this->item))
        ->and(ReaderPasswords::keyFor($this->item->id, 'harbour 2024'))->toBeNull()
        ->and(ReaderPasswords::keyFor(cmItem($this->editor, 'Open news')->id, ''))->toBeNull()
        ->and(ReaderPasswords::keyFor(strtolower((string) Str::ulid()), 'Harbour 2024'))->toBeNull();
});

it('keeps a password of 1 to 255 characters with one that isn\'t a space, spaces kept (I-1700)', function (string $password, bool $kept): void {
    expect(ReaderPasswords::problem($password))->toBe($kept ? null : 'Enter a password of up to 255 characters.');
})->with([
    'one character' => ['h', true],
    'spaces inside and around' => [' harbour  2024 ', true],
    '255 characters, some of them two bytes' => [str_repeat('é', 255), true],
    'empty' => ['', false],
    'spaces only' => ['   ', false],
    '256 characters' => [str_repeat('h', 256), false],
]);

it('fingerprints a password without holding it, and gives nothing for a public item (I-1700)', function (): void {
    $fingerprint = ReaderPasswords::fingerprint($this->item);

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fingerprint)->not->toContain('Harbour')
        ->and(ReaderPasswords::fingerprint(($this->protect)($this->item, ReaderPasswords::seal('Harbour 2024'))))->toBe($fingerprint)
        ->and(ReaderPasswords::fingerprint(cmItem($this->editor, 'Open news')))->toBe('');
});
