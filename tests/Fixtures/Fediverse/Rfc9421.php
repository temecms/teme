<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

/**
 * Signs a request the way Mastodon signs with RFC 9421: Content-Digest,
 * Signature-Input and Signature under the label sig1, written out by hand
 * from the RFC so the tests check Teme against the standard, not against
 * itself. For tests only.
 */
final class Rfc9421
{
    /**
     * The headers to add: content-digest (for a body), signature-input and signature.
     *
     * @param  array<string, string>  $headers  the request's headers, lower-cased names
     * @param  list<string>  $components  the covered components, in order
     * @param  array<string, int|string>  $params  replacing created, keyid and alg
     *
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $url,
        array $headers,
        string $body,
        string $keyId,
        array $components = ['@method', '@target-uri', 'content-digest'],
        array $params = [],
        string $privateKey = '',
    ): array {
        $added = [];

        if ($body !== '') {
            $added['content-digest'] = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
        }

        $all = [...$headers, ...$added];
        $parts = (array) parse_url($url);
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $lines = [];

        foreach ($components as $component) {
            $lines[] = '"'.$component.'": '.match ($component) {
                '@method' => strtoupper($method),
                '@target-uri' => $url,
                '@authority' => strtolower((string) ($parts['host'] ?? '')),
                '@scheme' => (string) ($parts['scheme'] ?? 'https'),
                '@path' => $path,
                '@query' => $query === '' ? '?' : $query,
                '@request-target' => $path.$query,
                default => trim($all[$component] ?? ''),
            };
        }

        $values = ['created' => time(), 'keyid' => $keyId, 'alg' => 'rsa-v1_5-sha256', ...$params];
        $signatureParams = '('.implode(' ', array_map(fn (string $c): string => '"'.$c.'"', $components)).')';

        foreach ($values as $name => $value) {
            $signatureParams .= ';'.$name.'='.(is_int($value) ? $value : '"'.$value.'"');
        }

        $lines[] = '"@signature-params": '.$signatureParams;
        openssl_sign(implode("\n", $lines), $signature, $privateKey !== '' ? $privateKey : Actors::keys('remote')['private'], OPENSSL_ALGO_SHA256);

        return [
            ...$added,
            'signature-input' => 'sig1='.$signatureParams,
            'signature' => 'sig1=:'.base64_encode((string) $signature).':',
        ];
    }
}
