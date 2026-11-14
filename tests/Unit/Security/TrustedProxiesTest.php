<?php

declare(strict_types=1);

use App\Domain\Support\Security\TrustedProxies;

it('parses a comma-separated list', function (): void {
    expect(TrustedProxies::parse(' 10.0.0.1, 192.168.0.0/16 ,'))->toBe(['10.0.0.1', '192.168.0.0/16']);
});

it('trusts nobody by default', function (): void {
    expect(TrustedProxies::parse(''))->toBe([]);
});

it('refuses to trust every proxy', function (string $value): void {
    expect(fn () => TrustedProxies::parse($value))->toThrow(InvalidArgumentException::class);
})->with(['*', '**', '10.0.0.1,*', '0.0.0.0/0', '::/0', 'REMOTE_ADDR', 'PRIVATE_SUBNETS']);

it('refuses what isn’t an address, a range or a list it knows, naming it (I-380)', function (string $entry): void {
    expect(fn () => TrustedProxies::parse('private, '.$entry))->toThrow(function (InvalidArgumentException $refusal) use ($entry): void {
        expect($refusal->getMessage())->toBe('TRUSTED_PROXIES may list addresses, ranges, private and cloudflare; "'.$entry.'" is none of them. "*" and a range of /0 would trust every client.');
    });
})->with(['cloudfare', '10.0.0.0/33', '10.0.0.1/', 'fd00::/129', 'proxy.internal']);

it('reads private and cloudflare as their ranges, beside addresses of its own, each once', function (): void {
    expect(TrustedProxies::parse('private'))->toBe(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'])
        ->and(TrustedProxies::parse('Private, 203.0.113.0/24, cloudflare, 10.0.0.0/8'))->toBe([...TrustedProxies::PRIVATE, '203.0.113.0/24', ...TrustedProxies::CLOUDFLARE]);
});

it('holds Cloudflare’s published ranges', function (): void {
    expect(TrustedProxies::CLOUDFLARE)->toBe([
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ]);
});

it('trusts the private ranges in production when TRUSTED_PROXIES is unset, and nothing elsewhere', function (): void {
    expect(TrustedProxies::resolve(null, true))->toBe(TrustedProxies::PRIVATE)
        ->and(TrustedProxies::resolve(null, false))->toBe([]);
});

it('keeps an empty TRUSTED_PROXIES empty in production: no proxy trusted', function (): void {
    expect(TrustedProxies::resolve('', true))->toBe([])
        ->and(TrustedProxies::resolve('cloudflare', true))->toBe(TrustedProxies::CLOUDFLARE);
});

it('takes IPv6 addresses and ranges', function (): void {
    expect(TrustedProxies::parse('fd00::/8, ::1'))->toBe(['fd00::/8', '::1']);
});
