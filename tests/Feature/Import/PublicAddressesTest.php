<?php

declare(strict_types=1);

use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Network\CheckedAddress;
use App\Domain\Support\Network\PublicAddresses;

beforeEach(function (): void {
    config(['teme.import.local_sources' => false]);
    importResolve([
        'old.example' => ['93.184.216.34'],
        'dual.example' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        'six.example' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'nat64.example' => ['64:ff9b::5db8:d822'],
        'sneaky.example' => ['93.184.216.34', '10.0.0.5'],
        'old-site.example' => ['127.0.0.1'],
    ]);
    $this->check = fn (string $url): CheckedAddress => app(PublicAddresses::class)->check($url);
});

it('accepts a public name, and pins the address it resolved to', function (): void {
    $address = ($this->check)('https://Old.Example/blog/');

    expect($address->host)->toBe('old.example')
        ->and($address->ip)->toBe('93.184.216.34')
        ->and($address->port)->toBe(443)
        ->and($address->origin())->toBe('https://old.example')
        ->and($address->curlResolve())->toBe('old.example:443:93.184.216.34');
});

it('pins an IPv6 address in brackets, and reads the IPv4 address inside a NAT64 one', function (): void {
    expect(($this->check)('https://dual.example')->curlResolve())->toBe('dual.example:443:93.184.216.34')
        ->and(($this->check)('https://six.example')->curlResolve())->toBe('six.example:443:[2606:2800:220:1:248:1893:25c8:1946]')
        ->and(($this->check)('https://nat64.example:8443')->curlResolve())->toBe('nat64.example:8443:[64:ff9b::5db8:d822]')
        ->and(($this->check)('https://93.184.216.34')->curlResolve())->toBeNull();
});

it('refuses an address that isn\'t public', function (string $ip): void {
    importResolve(['inside.example' => [$ip]]);

    expect(fn () => ($this->check)('https://inside.example'))
        ->toThrow(AddressRefused::class, 'inside.example is on a private network, so Teme won’t connect to it.');
})->with([
    'loopback' => ['127.0.0.1'],
    'a private network' => ['10.0.0.5'],
    'a cloud\'s metadata address' => ['169.254.169.254'],
    'nowhere' => ['0.0.0.0'],
    'shared address space' => ['100.64.0.1'],
    'multicast' => ['224.0.0.1'],
    'IPv6 loopback' => ['::1'],
    'an IPv6 unique local address' => ['fd00::1'],
    'loopback, IPv4-mapped' => ['::ffff:127.0.0.1'],
    'a private network, by 6to4' => ['2002:a00:1::'],
]);

it('refuses a name with any private address among its public ones', function (): void {
    expect(fn () => ($this->check)('https://sneaky.example'))
        ->toThrow(AddressRefused::class, 'sneaky.example is on a private network, so Teme won’t connect to it.');
});

it('refuses an address curl would read another way, or one that carries a password', function (string $url, string $words): void {
    expect(fn () => ($this->check)($url))->toThrow(AddressRefused::class, $words);
})->with([
    'a decimal address' => ['http://2130706433/', 'Write the site’s address with its name, as https://example.com.'],
    'an octal address' => ['https://0177.0.0.1/', 'Write the site’s address with its name, as https://example.com.'],
    'a hexadecimal address' => ['https://0x7f.0.0.1/', 'Write the site’s address with its name, as https://example.com.'],
    'a shortened address' => ['https://127.1/', 'Write the site’s address with its name, as https://example.com.'],
    'no scheme' => ['old.example', 'Write the site’s address with its name, as https://example.com.'],
    'another scheme' => ['ftp://old.example/', 'The address must start with https://.'],
    'a user name and password' => ['https://ada:secret@old.example/', 'Leave the user name and password out of the address: Teme asks for them separately.'],
    'a name ending in a dot, which curl would look up without the pin' => ['https://old.example./', 'Write the site’s address with its name, as https://example.com.'],
    'a control character' => ["https://old.example/\x00wp-json", 'Write the site’s address with its name, as https://example.com.'],
]);

it('refuses a name it can\'t find, and plain http to a public site', function (): void {
    expect(fn () => ($this->check)('https://nowhere.example'))->toThrow(AddressRefused::class, 'Teme couldn’t find nowhere.example. Check the address.')
        ->and(fn () => ($this->check)('http://old.example'))->toThrow(AddressRefused::class, 'Use https:// for this site. Plain http:// is only for a site on this computer.');
});

it('reaches a site on this computer, over http too, only where local sources are on', function (): void {
    expect(fn () => ($this->check)('http://old-site.example'))->toThrow(AddressRefused::class, 'old-site.example is on a private network, so Teme won’t connect to it.');

    config(['teme.import.local_sources' => true]);

    expect(($this->check)('http://old-site.example')->curlResolve())->toBe('old-site.example:80:127.0.0.1')
        ->and(fn () => ($this->check)('http://old.example'))->toThrow(AddressRefused::class, 'Use https:// for this site. Plain http:// is only for a site on this computer.');
});

it('reaches a public site over plain http only where the caller allows it, still pinned', function (): void {
    expect(fn () => ($this->check)('http://old.example/harbour.jpg'))->toThrow(AddressRefused::class, 'Use https:// for this site. Plain http:// is only for a site on this computer.')
        ->and(app(PublicAddresses::class)->check('http://old.example/harbour.jpg', plainHttp: true)->curlResolve())->toBe('old.example:80:93.184.216.34');
});
