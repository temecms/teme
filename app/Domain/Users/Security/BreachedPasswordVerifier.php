<?php

/**
 * Have I Been Pwned by k-anonymity: only five characters of the SHA-1 hash
 * leave the server. Fails open — an outage must never stop an editor setting
 * a password — and says so in the log. Study G, S3.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Security;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BreachedPasswordVerifier implements UncompromisedVerifier
{
    private const int TIMEOUT_SECONDS = 3;

    /**
     * @param  array{value?: string, threshold?: int}  $data
     */
    public function verify($data): bool
    {
        $hash = strtoupper(sha1((string) ($data['value'] ?? '')));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);
        $threshold = (int) ($data['threshold'] ?? 0);

        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (Throwable $exception) {
            Log::warning('Breached-password check unavailable; the password was accepted unchecked.', [
                'reason' => $exception->getMessage(),
            ]);

            return true;
        }

        if (! $response->successful()) {
            Log::warning('Breached-password check unavailable; the password was accepted unchecked.', [
                'status' => $response->status(),
            ]);

            return true;
        }

        foreach (preg_split('/\R/', trim($response->body())) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');

            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > $threshold) {
                return false;
            }
        }

        return true;
    }
}
