<?php

declare(strict_types=1);

use App\Domain\Extensions\Sources\SafeAddress;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Support\Network\AddressRefused;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

beforeEach(function (): void {
    app()->instance(HostResolver::class, FakeResolver::answering([
        'git.example.com' => ['93.184.216.34'],
        'localhost' => ['127.0.0.1', '::1'],
        'git.internal' => ['10.0.0.5'],
        'mixed.example.com' => ['93.184.216.34', '192.168.1.10'],
    ]));
    config(['teme.extensions.private_hosts' => false]);
});

it('refuses an address Composer mustn\'t fetch from', function (string $url, string $reason): void {
    expect(fn () => app(SafeAddress::class)->check($url))
        ->toThrow(fn (AddressRefused $refused) => expect($refused->reason)->toBe($reason));
})->with([
    'plain http' => ['http://git.example.com/acme/plugin.git', AddressRefused::SCHEME],
    'ssh' => ['ssh://git@git.example.com/acme/plugin.git', AddressRefused::SCHEME],
    'a key in the address' => ['https://token:secret@git.example.com/acme/plugin.git', AddressRefused::CREDENTIALS],
    'localhost' => ['https://localhost/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'loopback' => ['https://127.0.0.1/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'a private network' => ['https://10.1.2.3/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'a name on a private network' => ['https://git.internal/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'one private address among public ones' => ['https://mixed.example.com/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'the cloud metadata address' => ['https://169.254.169.254/latest/meta-data', AddressRefused::NOT_PUBLIC],
    'IPv6 loopback' => ['https://[::1]/acme/plugin.git', AddressRefused::NOT_PUBLIC],
    'a host that doesn\'t resolve' => ['https://nowhere.example.com/acme/plugin.git', AddressRefused::UNKNOWN_HOST],
    'no host' => ['https:///acme/plugin.git', AddressRefused::MALFORMED],
    'a space' => ['https://git.example.com/acme plugin.git', AddressRefused::MALFORMED],
]);

it('allows an https address whose host resolves to public addresses only', function (): void {
    app(SafeAddress::class)->check('https://git.example.com/acme/plugin.git');
})->throwsNoExceptions();

it('allows a private host when the operator allows private hosts, still only over https', function (): void {
    config(['teme.extensions.private_hosts' => true]);

    app(SafeAddress::class)->check('https://git.internal/acme/plugin.git');
    app(SafeAddress::class)->check('https://10.1.2.3/acme/plugin.git');

    expect(fn () => app(SafeAddress::class)->check('http://git.internal/acme/plugin.git'))->toThrow(AddressRefused::class);
});
