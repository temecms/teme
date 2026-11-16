<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes\Verifiers;

use Teme\Contracts\Routes\PublicRequest;

/**
 * Compares a named header, field or query value to the plugin's secret with
 * hash_equals.
 */
final class SecretVerifier
{
    /**
     * @param  array{kind: string, from?: string, secret?: string}  $verify
     */
    public function passes(PublicRequest $request, array $verify, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $from = $verify['from'] ?? '';
        $value = $this->read($request, $from);

        return is_string($value) && hash_equals($secret, $value);
    }

    private function read(PublicRequest $request, string $from): ?string
    {
        if (! str_contains($from, ':')) {
            return null;
        }

        [$kind, $name] = explode(':', $from, 2);
        $name = trim($name);

        return match ($kind) {
            'header' => $request->headers[strtolower($name)] ?? null,
            'query' => $request->query[$name] ?? null,
            'field' => $this->field($request, $name),
            default => null,
        };
    }

    private function field(PublicRequest $request, string $name): ?string
    {
        $type = $request->headers['content-type'] ?? '';

        if (str_contains($type, 'application/json')) {
            $decoded = json_decode($request->rawBody, true);

            return is_array($decoded) && is_string($decoded[$name] ?? null) ? $decoded[$name] : null;
        }

        parse_str($request->rawBody, $fields);

        return is_string($fields[$name] ?? null) ? $fields[$name] : null;
    }
}
