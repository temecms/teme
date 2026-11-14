<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\PlacementContributor;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\Policy\PagePolicy;

/**
 * Site code on every page the document shell draws (I-288): each entry that
 * is on, in its placement and the order it was added, as one fragment holding
 * the code exactly as an administrator wrote it, at the site's precedence,
 * its hashes and origins noted for the page's content security policy.
 * Nothing while the host disallows site code, or while this request's page
 * withholds it, as the signed-in preview does (I-1324).
 */
final class SiteCodeFragments implements PlacementContributor
{
    public function precedence(): Precedence
    {
        return Precedence::Site;
    }

    public function fragments(Placement $placement, DocumentPage $page): array
    {
        if (SiteCode::disallowed() || app(SiteCodeWithheld::class)->isWithheld()) {
            return [];
        }

        $policy = app(PagePolicy::class);
        $fragments = [];

        foreach (SiteCodeEntries::on()[$placement->value] ?? [] as $entry) {
            // What the entry needs from the page's policy, worked out from its saved code (I-3375).
            $policy->allowScriptHashes($entry['policy']['scripts']);
            $policy->allowHandlerHashes($entry['policy']['handlers']);

            foreach ($entry['policy']['origins'] as $directive => $origins) {
                $policy->allowOrigins($directive, $origins);
            }

            $fragments[] = new Fragment('site-code.'.$entry['id'], $entry['code']);
        }

        return $fragments;
    }
}
