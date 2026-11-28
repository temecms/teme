<?php

declare(strict_types=1);

use Teme\Contracts\Content\WriteRefused;
use Teme\Testing\FakePluginSecretStore;
use Teme\Testing\FakeSecurityLines;

it('keeps the lines a plugin writes, and refuses what the real door refuses', function (): void {
    $lines = new FakeSecurityLines(['probe'], mostPerHour: 2);

    expect($lines->write('probe', ' Looked for a page ', userId: 7, address: '203.0.113.5'))->toBeTrue()
        ->and($lines->write('probe', 'Second'))->toBeTrue()
        ->and($lines->write('probe', 'Third'))->toBeFalse()
        ->and($lines->lines())->toHaveCount(2)
        ->and($lines->lines()[0])->toBe(['kind' => 'probe', 'text' => 'Looked for a page', 'userId' => 7, 'address' => '203.0.113.5']);

    expect(fn () => $lines->write('other', 'Text'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $lines->write('probe', '   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $lines->write('probe', 'Text', address: 'someone@example.test'))->toThrow(InvalidArgumentException::class);
});

it('keeps a free secret in memory, with the key, value and count rules of the real store', function (): void {
    $store = new FakePluginSecretStore;

    $store->put('oauth_token', 'token-value', 'actor-1');

    expect($store->get('oauth_token'))->toBe('token-value')
        ->and(fn () => $store->put('bad.key', 'value', 'actor-1'))->toThrow(WriteRefused::class)
        ->and(fn () => $store->put('token', '', 'actor-1'))->toThrow(WriteRefused::class)
        ->and(fn () => $store->put('token', str_repeat('a', 4097), 'actor-1'))->toThrow(WriteRefused::class);

    $store->forget('oauth_token', 'actor-1');

    expect($store->get('oauth_token'))->toBeNull();
});
