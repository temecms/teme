<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The one request the update check makes (I-?? draft): Teme's release list
 * from GitHub's API, with the read-only token while the repository is
 * private, sending nothing about this site; and what the answer means.
 */
final readonly class GitHubReleases
{
    public const string API = 'https://api.github.com';

    /** A fixed word: no version and no address (I-407). */
    public const string USER_AGENT = 'Teme';

    public const string AGAIN = 'Teme asks again within the hour.';

    /**
     * Asks for the release list, sending the last answer's ETag when there is one.
     */
    public function ask(?string $etag): ReleaseCheck
    {
        $repository = (string) config('teme.updates.repository');
        $token = config('teme.updates.token');
        $hasToken = is_string($token) && $token !== '';
        $request = Http::withUserAgent(self::USER_AGENT)
            ->accept('application/vnd.github+json')
            ->withHeaders(array_filter(['X-GitHub-Api-Version' => '2022-11-28', 'If-None-Match' => $etag], fn (?string $value): bool => $value !== null && $value !== ''))
            // A 3xx is recorded as GitHub's answer, never followed to an address it names.
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(15);

        if ($hasToken) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get(self::API."/repos/{$repository}/releases", ['per_page' => 100]);
        } catch (ConnectionException) {
            return ReleaseCheck::failed('Teme couldn’t reach GitHub. It asks again within the hour.');
        }

        $status = $response->status();
        $limited = $status === 429 || ($status === 403 && $response->header('X-RateLimit-Remaining') === '0');

        return match (true) {
            $status === 304 => ReleaseCheck::unchanged(),
            $status === 200 => $this->found($response->json(), $repository, $response->header('ETag')),
            $limited => ReleaseCheck::failed('GitHub’s limit on requests was reached. '.self::AGAIN),
            $status === 401 => ReleaseCheck::failed('GitHub refused the token in TEME_GITHUB_TOKEN. Make a new read-only token for the repository and set it.'),
            $status === 403 => ReleaseCheck::failed('GitHub refused the request (403). '.self::AGAIN),
            $status === 404 => ReleaseCheck::failed($hasToken
                ? "GitHub can’t find {$repository}, or the token in TEME_GITHUB_TOKEN can’t read it."
                : "GitHub can’t find {$repository}. ".self::AGAIN),
            default => ReleaseCheck::failed("GitHub answered {$status}. ".self::AGAIN),
        };
    }

    private function found(mixed $items, string $repository, string $etag): ReleaseCheck
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return ReleaseCheck::failed('GitHub’s answer couldn’t be read. '.self::AGAIN);
        }

        $releases = [];

        foreach ($items as $item) {
            $release = is_array($item) ? Release::fromGitHub($item, $repository) : null;

            // GitHub lists newest first; a version seen already keeps its first item.
            if ($release !== null && ! isset($releases[$release->version])) {
                $releases[$release->version] = $release;
            }
        }

        return ReleaseCheck::found(array_values($releases), $etag === '' ? null : $etag);
    }
}
