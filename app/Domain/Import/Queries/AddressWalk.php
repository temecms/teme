<?php

/**
 * What this site answers at each address the old site lists, asked as a
 * reader would (I-339), through the whole application in this process. It
 * writes nothing itself; its requests write what a reader's do, as
 * docs/architecture/addresses.md describes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Addresses\Paths;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\Check\CheckStep;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Redirects\RedirectSource;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AddressWalk
{
    /** The statuses that send readers on. */
    private const array ONWARD = [301, 302, 307, 308];

    public function __construct(private CompanionClients $clients, private OldSiteUrls $urls) {}

    /**
     * Asks for every address the source lists, calling $each once for each
     * that answers neither a page nor a redirect that reaches one: with the
     * old address, 'status' or 'query', the status it ended on and where it
     * was sent last. Returns the counts: answers with a page, with a redirect
     * to a page here, with a redirect to a file or another site, those that
     * don't answer, and the addresses on another host that weren't asked.
     *
     * @param  Closure(string, string, int|null, string|null): void  $each
     *
     * @return array{direct: int, moved: int, away: int, bad: int, others: int}
     */
    public function walk(ImportSource $source, Closure $each): array
    {
        $reader = $this->clients->for($source);
        $host = OldSiteUrls::hostOf((string) $source->id);
        $counts = ['direct' => 0, 'moved' => 0, 'away' => 0, 'bad' => 0, 'others' => 0];
        $cursor = null;

        do {
            $page = $this->urls->page($reader, OldSiteUrls::KINDS, $cursor);
            $cursor = $page['cursor'];

            foreach ($page['items'] as $item) {
                foreach ($this->addresses($page['kind'], $item) as $address) {
                    $this->ask($address, $host, $each, $counts);
                }
            }
        } while ($cursor !== null);

        return $counts;
    }

    /**
     * One item's addresses: its own, or each file of an attachment.
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<string>
     */
    private function addresses(string $kind, array $item): array
    {
        $addresses = $kind === 'attachment_file'
            ? array_values(array_unique(array_filter((array) ($item['files'] ?? []), is_string(...))))
            : [is_string($item['url'] ?? null) ? $item['url'] : ''];

        return array_values(array_filter($addresses, fn (string $address): bool => $address !== ''));
    }

    /**
     * One old address asked, and counted.
     *
     * @param  Closure(string, string, int|null, string|null): void  $each
     * @param  array{direct: int, moved: int, away: int, bad: int, others: int}  $counts
     */
    private function ask(string $address, string $host, Closure $each, array &$counts): void
    {
        $own = OldSiteUrls::own($address, $host);

        if ($own === null) {
            $counts['others']++;

            return;
        }

        if ($own['query'] !== '') {
            $each($address, 'query', null, null);
            $counts['bad']++;

            return;
        }

        $path = $own['path'];
        $response = $this->get($path);
        $location = null;
        $followed = false;

        for ($hop = 0; $hop < CheckStep::HOPS && in_array($response->getStatusCode(), self::ONWARD, true); $hop++) {
            $location = (string) $response->headers->get('Location');
            $next = RedirectSource::onThisSite($location);

            // Another site's, or the site's own files: nothing more to ask here.
            if ($next === null || ! str_starts_with($next, '/') || Paths::taken(explode('/', trim($next, '/'))[0])) {
                $counts['away']++;

                return;
            }

            $followed = true;
            $response = $this->get($next);
        }

        if ($response->getStatusCode() === 200) {
            $counts[$followed ? 'moved' : 'direct']++;

            return;
        }

        $each($address, 'status', $response->getStatusCode(), $location);
        $counts['bad']++;
    }

    /**
     * What the application answers at this path, asked as a reader would:
     * a GET with no cookies, in a fresh scope as the queue worker gives each
     * job, and the process's own request put back after.
     */
    private function get(string $path): Response
    {
        $app = app();
        $app->forgetScopedInstances();
        $previous = $app->bound('request') ? $app->make('request') : null;
        $kernel = $app->make(HttpKernel::class);
        $request = Request::create(rtrim((string) config('app.url'), '/').$path);

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        } finally {
            if ($previous instanceof Request) {
                $app->instance('request', $previous);
            }
        }
    }
}
