<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Site\Policy\PagePolicy;

/**
 * Origins collected while an article's body was drawn with insertions,
 * applied to the page's policy after it has been started for the response
 * (I-064). PathResolver builds the article before PublicPage starts the
 * policy, so origins cannot be written there.
 */
final class InsertionOrigins
{
    /** @var array<string, array<string, true>> */
    private array $origins = [];

    /**
     * @param  array<string, list<string>>  $origins
     */
    public function add(array $origins): void
    {
        foreach ($origins as $directive => $hosts) {
            foreach ($hosts as $host) {
                $this->origins[$directive][$host] = true;
            }
        }
    }

    /** Writes the collected origins into the page's policy. */
    public function apply(PagePolicy $policy): void
    {
        foreach ($this->origins as $directive => $hosts) {
            $policy->allowOrigins($directive, array_keys($hosts));
        }
    }
}
