<?php

/**
 * Cleans markup a plugin gives a public page, a page insertion or a card,
 * and gives the page's policy what the kept markup needs: its origins, its
 * plugin's approved scripts and the hosts the plugin declared.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Site\Insertions\Scripts\ApprovedScripts;
use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Contracts\Container\Container;

/**
 * Cleans markup a plugin gives a public page and notes in the page's
 * policy what the kept markup needs, so page insertions and plugin cards
 * are cleaned by one set of rules. The head keeps less than the body does.
 */
final readonly class PluginMarkup
{
    public function __construct(
        private Container $container,
        private InsertionOrigins $origins,
    ) {}

    /**
     * The markup, cleaned. Its origins and its plugin's approved scripts
     * join the page's policy, and, when $hosts and anything is kept, the
     * hosts the plugin declared for what it inserts.
     *
     * @param  string|null  $code  the plugin's code; null for a contributor that isn't a plugin's
     */
    public function clean(?string $code, string $html, bool $head = false, bool $hosts = true): string
    {
        $approved = $code === null ? [] : ApprovedScripts::for($code);
        $cleaned = $head ? HeadCleaner::clean($html, $approved) : InsertionCleaner::clean($html, $approved);
        $policy = $this->container->make(PagePolicy::class);

        foreach ($cleaned['origins'] as $directive => $origins) {
            $policy->allowOrigins($directive, $origins);
        }

        $policy->allowScriptHashes($cleaned['scripts']);

        if ($code !== null && $hosts && trim($cleaned['html']) !== '') {
            foreach ($this->origins->declared($code) as $directive => $origins) {
                $policy->allowOrigins($directive, $origins);
            }
        }

        return $cleaned['html'];
    }
}
