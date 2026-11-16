<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use App\Domain\Fediverse\Models\FediverseActor;
use RuntimeException;
use SensitiveParameter;

/**
 * Signs a request one of the site's actors sends, draft-cavage style
 * (proposal, Settled 5): a SHA-256 digest of a POST's body, the date, and
 * an rsa-sha256 signature over the request target, host, date and digest.
 * The private key is opened only here and never returned, logged or passed on.
 */
final class Signer
{
    /**
     * The headers to add to the request: Date, Digest for a POST, and Signature.
     *
     * @param  array<string, string>  $headers  the request's own headers; a Host there wins over the URL's
     *
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, #[SensitiveParameter] string $body, FediverseActor $actor): array
    {
        $method = strtoupper($method);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new RuntimeException('A signed request needs an absolute address.');
        }

        $lower = array_change_key_case($headers, CASE_LOWER);
        $host = $lower['host'] ?? self::host($parts);
        $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $added = ['Date' => now()->utc()->format('D, d M Y H:i:s').' GMT'];
        $covered = ['(request-target)' => strtolower($method).' '.$target, 'host' => $host, 'date' => $added['Date']];

        if ($method === 'POST') {
            $added['Digest'] = 'SHA-256='.base64_encode(hash('sha256', $body, true));
            $covered['digest'] = $added['Digest'];
        }

        $lines = [];

        foreach ($covered as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $added['Signature'] = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $actor->keyId(),
            implode(' ', array_keys($covered)),
            base64_encode($this->signature(implode("\n", $lines), $actor)),
        );

        return $added;
    }

    private function signature(string $string, FediverseActor $actor): string
    {
        $key = openssl_pkey_get_private($actor->private_key);

        if ($key === false || ! openssl_sign($string, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The actor’s key could not sign.');
        }

        return (string) $signature;
    }

    /**
     * The URL's host, with its port when it isn't the scheme's own.
     *
     * @param  array{scheme?: string, host?: string, port?: int}  $parts
     */
    private static function host(array $parts): string
    {
        $host = strtolower($parts['host'] ?? '');
        $default = ($parts['scheme'] ?? 'https') === 'http' ? 80 : 443;

        return isset($parts['port']) && $parts['port'] !== $default ? $host.':'.$parts['port'] : $host;
    }
}
