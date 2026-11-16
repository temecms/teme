<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PublicAddress;
use App\Domain\Support\Network\AddressRefused;
use RuntimeException;

/**
 * Checks a Git or vendor address before the installer hands it to Composer:
 * https only, no user name or key written into it, and a host that resolves,
 * to public addresses only by the plugin HTTP guard's ranges, unless the
 * operator allows private hosts for a server on their own network (I-211).
 */
final readonly class SafeAddress
{
    public function __construct(private HostResolver $resolver) {}

    /**
     * Checks the address of the saved source a request names, read without
     * its key.
     *
     * @throws AddressRefused when Composer mustn't fetch from it
     * @throws RuntimeException when the source is gone
     */
    public function checkSource(ExtensionRequest $request, string $kind): void
    {
        $url = $request->sourceId === null ? null : ExtensionSourceRecord::query()
            ->whereKey($request->sourceId)
            ->where('kind', $kind)
            ->value('url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(__('The package source this request names was removed. Add the source again, then ask again.'));
        }

        $this->check($url);
    }

    /**
     * Returns when Composer may fetch from this address.
     *
     * @throws AddressRefused when it mustn't, with the words people read
     */
    public function check(string $url): void
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw AddressRefused::malformed();
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw AddressRefused::malformed();
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw AddressRefused::scheme();
        }

        // A key in the address would be written into the release's composer.json.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw AddressRefused::credentials();
        }

        $host = strtolower($parts['host']);

        if (str_ends_with($host, '.')) {
            throw AddressRefused::malformed();
        }

        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : $this->resolver->addresses($host);

        if ($addresses === []) {
            throw AddressRefused::unknownHost($host);
        }

        if ((bool) config('teme.extensions.private_hosts')) {
            return;
        }

        foreach ($addresses as $address) {
            if (! PublicAddress::is($address)) {
                throw AddressRefused::notPublic($host);
            }
        }
    }
}
