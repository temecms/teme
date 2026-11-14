<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\PublicAddress;

it('tells public addresses from private and reserved ones', function (string $ip, bool $public): void {
    expect(PublicAddress::is($ip))->toBe($public);
})->with([
    ['10.0.0.5', false],
    ['127.0.0.1', false],
    ['192.168.1.2', false],
    ['::1', false],
    ['::ffff:127.0.0.1', false],
    ['100.64.0.1', false],
    ['fe80::1', false],
    ['169.254.169.254', false],
    ['0.0.0.0', false],
    ['192.0.0.1', false],
    ['198.18.0.1', false],
    ['224.0.0.1', false],
    ['255.255.255.255', false],
    ['fc00::1', false],
    ['ff02::1', false],
    ['64:ff9b::7f00:1', false],
    ['64:ff9b:1::1', false],
    ['2002:7f00:1::1', false],
    ['2001:db8::1', false],
    ['::ffff:93.184.215.14', false],
    ['::7f00:1', false],
    ['::ffff:7f00:1', false],
    ['fec0::1', false],
    ['3fff::1', false],
    ['5f00::1', false],
    ['192.88.99.1', false],
    ['not an address', false],
    ['93.184.215.14', true],
    ['2606:2800:220:1::248', true],
]);
