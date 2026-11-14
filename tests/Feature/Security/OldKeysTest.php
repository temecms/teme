<?php

declare(strict_types=1);

use App\Domain\Security\OldKeys;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Security\OldKey;

beforeEach(function (): void {
    // Whether the keys open what $key wrote, tried as a run tries them:
    // some other key as this server's, and no previous keys of its own.
    $this->opens = function (OldKeys $keys, OldKey $key): bool {
        $encrypter = $keys->encrypter('AES-256-CBC', random_bytes(32), []);

        try {
            return $encrypter?->decryptString($key->encrypter->encryptString('a secret')) === 'a secret';
        } catch (Throwable) {
            return false;
        }
    };
});

it('reads a key as an environment holds it, with its prefix, without it, or raw', function (): void {
    $key = OldKey::make();
    $plain = OldKey::from('abcdefghijklmnopqrstuvwxyz012345');

    expect(($this->opens)(OldKeys::fromText($key->typed), $key))->toBeTrue()
        ->and(($this->opens)(OldKeys::fromText(substr($key->typed, 7)), $key))->toBeTrue()
        ->and(($this->opens)(OldKeys::fromText('  '.$key->typed."  \n"), $key))->toBeTrue()
        ->and(($this->opens)(OldKeys::fromText($plain->raw), $plain))->toBeTrue()
        ->and(($this->opens)(OldKeys::fromText(OldKey::make()->typed), $key))->toBeFalse();
});

it('reads a backup’s app-key.json, its previous keys too', function (): void {
    $key = OldKey::make();
    $previous = OldKey::make();
    $keys = OldKeys::fromAppKeyJson((string) json_encode(['key' => $key->typed, 'previous_keys' => [$previous->typed]]));

    expect(($this->opens)($keys, $key))->toBeTrue()
        ->and(($this->opens)($keys, $previous))->toBeTrue()
        ->and($keys->count())->toBe(2)
        ->and(OldKeys::fromAppKeyJson((string) json_encode(['key' => $key->typed]))->count())->toBe(1)
        ->and(OldKeys::fromFileContents("\n ".json_encode(['key' => $key->typed, 'previous_keys' => []]))->count())->toBe(1);
});

it('reads keys on lines, and lines copied from a .env file', function (): void {
    [$one, $two, $three] = [OldKey::make(), OldKey::make(), OldKey::make()];

    $keys = OldKeys::fromFileContents(implode("\n", [
        '# the old server',
        'APP_NAME=Teme',
        "APP_KEY=\"{$one->typed}\"",
        "APP_PREVIOUS_KEYS={$two->typed},{$three->typed}",
        '',
        $one->typed,
    ]));

    expect($keys->count())->toBe(3)
        ->and(($this->opens)($keys, $one))->toBeTrue()
        ->and(($this->opens)($keys, $two))->toBeTrue()
        ->and(($this->opens)($keys, $three))->toBeTrue();
});

it('refuses what isn’t a key, never repeating what it was given', function (string $text, string $message): void {
    try {
        OldKeys::fromFileContents($text);
        $this->fail('Nothing was refused.');
    } catch (ValidationException $refusal) {
        expect($refusal->errors())->toBe(['old_key' => [$message]])
            ->and((string) json_encode($refusal->errors()))->not->toContain('hunter2');
    }
})->with([
    'nothing but a comment' => ["\n  \n# only a comment\n", OldKeys::NO_KEY],
    'an empty line from a .env file' => ['APP_KEY=', OldKeys::NO_KEY],
    'a broken prefix' => ['base64:hunter2!!', OldKeys::NOT_A_KEY],
    'JSON without a key' => ['{"secret": "hunter2"}', OldKeys::NOT_APP_KEY_JSON],
    'previous keys that aren’t a list' => ['{"key": "base64:aHVudGVyMg==", "previous_keys": "hunter2"}', OldKeys::NOT_APP_KEY_JSON],
]);

it('says when a key doesn’t fit the cipher, and nothing when it does', function (): void {
    expect(OldKeys::fromText('short-raw-key')->problem('AES-256-CBC'))
        ->toBe('That key doesn’t fit this server’s cipher, AES-256-CBC. Give the APP_KEY exactly as the old server had it.')
        ->and(OldKeys::fromText(OldKey::make()->typed)->problem('AES-256-CBC'))->toBeNull()
        ->and(OldKeys::none()->problem('AES-256-CBC'))->toBeNull();
});

it('never shows, encodes or serializes its keys', function (): void {
    $key = OldKey::make();
    $keys = $key->keys();

    expect(print_r($keys, true))->toContain('1 hidden')
        ->not->toContain($key->raw)
        ->not->toContain(substr($key->typed, 7))
        ->and(json_encode($keys))->toBe('{}')
        ->and(fn () => serialize($keys))->toThrow(LogicException::class, 'Old keys are never serialized.');
});
