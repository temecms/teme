<?php

/**
 * What the site answers at an address, found in-process with no HTTP and
 * nothing drawn or counted, in the order a reader's request meets it: the
 * path table, then the redirects, then an old WordPress file address the
 * import brought (docs/studies/import.md, I-1851). The importer
 * checks every old address with it before a site moves.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Redirects\SiteAnswer;
use App\Domain\Site\PathResolver;
use InvalidArgumentException;

final readonly class SiteAnswers
{
    public function __construct(private RedirectLookup $redirects) {}

    /**
     * Returns the status, where it sends readers and what answered, for a
     * path on this site, raw as a request carries it, or the site's own
     * address in full.
     *
     * @throws InvalidArgumentException for another site's address
     */
    public function at(string $address): SiteAnswer
    {
        $own = preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) === 1 ? RedirectSource::onThisSite($address) : $address;

        if ($own === null || ! str_starts_with($own, '/')) {
            throw new InvalidArgumentException("Not an address on this site: [{$address}].");
        }

        [$path, $query] = array_pad(explode('?', explode('#', $own, 2)[0], 2), 2, '');
        $answer = PathResolver::resolve($path, $query);

        if ($answer['kind'] === 'view') {
            return new SiteAnswer(200, null, 'page');
        }

        if ($answer['kind'] === 'redirect') {
            return new SiteAnswer(301, rtrim(url('/'), '/').($answer['redirect'] ?? '/'), 'address');
        }

        $redirect = $this->redirects->answer($path, $query);

        if ($redirect !== null) {
            return new SiteAnswer($redirect->status, $redirect->location, 'redirect');
        }

        $file = $this->redirects->oldFile($path);

        return $file === null ? new SiteAnswer(404, null, 'not found') : new SiteAnswer(301, $file, 'old file');
    }
}
