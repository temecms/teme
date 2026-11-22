<?php

/**
 * Reads a WordPress site through the Teme Companion plugin's export API
 * (I-334): over the checked address, pinned; the application password by
 * Basic authentication only; no redirect followed; every answer's shape
 * checked, and every refusal one SourceRefused with its words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

use App\Domain\Support\Network\CheckedAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

final class CompanionClient implements SourceReader
{
    /** The oldest export API this client reads. */
    public const int MIN_API = 1;

    private const string ROUTES = 'teme/v1/';

    public function __construct(
        private readonly CheckedAddress $site,
        private readonly string $apiRoot,
        private readonly string $userName,
        #[SensitiveParameter] private readonly string $password,
    ) {}

    /**
     * The plugin, its version and its API checked; then the settings read
     * with the password, which proves the user name, the password and the
     * administrator's rights at once, and gives WordPress's own home.
     *
     * @throws SourceRefused
     */
    public function probe(): SourceProbe
    {
        $probe = $this->get('probe', [], signedIn: false);

        if (($probe['name'] ?? null) !== 'teme-companion' || ! is_int($probe['api'] ?? null)) {
            throw SourceRefused::notCompanion();
        }

        if ($probe['api'] < self::MIN_API) {
            throw SourceRefused::tooOld($probe['api']);
        }

        if (($probe['application_passwords'] ?? false) !== true) {
            throw SourceRefused::noApplicationPasswords();
        }

        $home = $this->get('settings', [])['home'] ?? null;

        if (! is_string($home) || $home === '') {
            throw SourceRefused::badAnswer();
        }

        return new SourceProbe(rtrim($home, '/'), $this->apiRoot, (string) ($probe['version'] ?? ''), $probe['api']);
    }

    public function page(string $list, array $query, int $afterId, int $limit): SourcePage
    {
        $body = $this->get($list, [...$query, 'after_id' => $afterId, 'limit' => $limit]);
        $items = $body['items'] ?? null;
        $next = $body['next'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || ($next !== null && ! is_int($next['after_id'] ?? null))) {
            throw SourceRefused::badAnswer();
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                throw SourceRefused::badAnswer();
            }
        }

        return new SourcePage($items, $next === null ? null : $next['after_id']);
    }

    public function whole(string $list, array $query = []): array
    {
        return $this->get($list, $query);
    }

    /**
     * The refusal for a connection that failed, saying why in plain words
     * when cURL's error number tells, and logging it: never the error's own
     * text, which names the address asked.
     */
    private static function unreachable(ConnectionException $lost): SourceRefused
    {
        $error = preg_match('/cURL error (\d+)/', $lost->getMessage(), $match) === 1 ? (int) $match[1] : null;

        $why = match ($error) {
            6 => 'its name doesn’t lead to any server',
            7 => 'its server refused the connection',
            28 => 'it didn’t answer in time',
            35, 51, 53, 54, 58, 59, 60, 77, 80, 83, 90, 91 => 'its secure connection couldn’t be checked (its HTTPS certificate)',
            52, 55, 56 => 'the connection broke before it answered',
            default => null,
        };

        Log::warning($why === null ? 'Import: Teme couldn’t reach the site.' : "Import: Teme couldn’t reach the site: {$why}.", ['error' => $error]);

        return SourceRefused::unreachable($why);
    }

    /**
     * One GET of the export API, its answer decoded.
     *
     * @param  array<string, scalar>  $query
     *
     * @return array<string, mixed>
     *
     * @throws SourceRefused
     */
    private function get(string $route, array $query, bool $signedIn = true): array
    {
        try {
            $response = $this->request($signedIn)->get($this->url($route, $query));
        } catch (ConnectionException $lost) {
            throw self::unreachable($lost);
        }

        $this->refuseUnlessFine($response);
        $body = $response->json();

        if (! is_array($body) || (array_is_list($body) && $body !== [])) {
            throw SourceRefused::badAnswer();
        }

        return $body;
    }

    /**
     * A request pinned to the checked address, which follows no redirect,
     * and signs in only when asked.
     */
    private function request(bool $signedIn): PendingRequest
    {
        // No proxy from the environment: the pin and the address check apply to the site itself.
        $options = ['allow_redirects' => false, 'proxy' => ''];
        $pin = $this->site->curlResolve();

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        $request = Http::withOptions($options)->connectTimeout(10)->timeout(45)->acceptJson()->withUserAgent('Teme importer');

        return $signedIn ? $request->withBasicAuth($this->userName, $this->password) : $request;
    }

    /**
     * The route's address under the API root, whether that is /wp-json/ or ?rest_route=/.
     *
     * @param  array<string, scalar>  $query
     */
    private function url(string $route, array $query): string
    {
        $url = $this->apiRoot.self::ROUTES.$route;

        return $query === [] ? $url : $url.(str_contains($this->apiRoot, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @throws SourceRefused for every answer but a 2xx
     */
    private function refuseUnlessFine(Response $response): void
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return;
        }

        // WordPress's own error code, when the answer is its JSON; an HTML page has none.
        $code = $response->json('code');

        throw match (true) {
            $status >= 300 && $status < 400 => SourceRefused::moved($response->header('Location') ?: null),
            $status === 401 => SourceRefused::credentials(),
            // A firewall or Cloudflare's bot check answers before WordPress does: a page of its own, no WordPress code.
            $status === 403 && ($code === null || $response->header('cf-mitigated') !== '') => SourceRefused::blocked(),
            $status === 403 && $code === 'teme_https_required' => SourceRefused::httpsOnly(),
            $status === 403 => SourceRefused::notAdministrator(),
            $status === 404 => SourceRefused::notCompanion(),
            $status === 429 || $status >= 500 => SourceRefused::busy($status),
            default => SourceRefused::badAnswer(),
        };
    }
}
