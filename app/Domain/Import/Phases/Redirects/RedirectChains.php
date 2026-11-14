<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Redirects\RedirectStatus;
use App\Domain\Redirects\RedirectTarget;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an old site's chains of rules from becoming chains here (I-859): a
 * rule whose target already redirects takes that redirect's target, one
 * step on; and an import redirect that already sends readers to a rule's
 * address moves on to its target first, so no order of rules makes two
 * steps. A person's redirect is never moved.
 */
final readonly class RedirectChains
{
    /**
     * The redirect sent straight to where its target redirects now; or, in
     * words, why it can't be: it would come back, or lead nowhere.
     *
     * @param  array{from: string, to: string, contentId: string|null, mediaId: string|null, status: int}  $redirect
     *
     * @return array{from: string, to: string, contentId: string|null, mediaId: string|null, status: int}|string
     */
    public function forward(array $redirect): array|string
    {
        $to = $redirect['to'];

        if ($redirect['contentId'] !== null || $redirect['mediaId'] !== null || ! str_starts_with($to, '/') || strpbrk($to, '?#[') !== false) {
            return $redirect;
        }

        $next = DB::table('redirects')->where('kind', 'exact')->where('enabled', true)
            ->where('source', PublicRequestPath::normalise($to))
            ->first(['status', 'target_kind', 'target', 'target_path']);

        if ($next === null) {
            return $redirect;
        }

        $status = RedirectStatus::from((int) $next->status);

        if ($status->sendsNowhere()) {
            return "it leads to {$to}, which answers {$status->value}.";
        }

        if ($next->target_kind === RedirectTarget::ADDRESS && (string) $next->target_path === PublicRequestPath::normalise($redirect['from'])) {
            return "it would send readers back to {$redirect['from']}.";
        }

        return match ($next->target_kind) {
            RedirectTarget::CONTENT => [...$redirect, 'to' => '', 'contentId' => (string) $next->target],
            RedirectTarget::MEDIA => [...$redirect, 'to' => '', 'mediaId' => (string) $next->target],
            default => [...$redirect, 'to' => (string) $next->target],
        };
    }

    /**
     * Moves each import redirect that sends readers to this redirect's
     * address on to where this one sends them, before it is written; none
     * for a pattern, or for a redirect that sends readers nowhere.
     *
     * @param  array{from: string, to: string, contentId: string|null, mediaId: string|null, status: int}  $redirect
     */
    public function repoint(array $redirect, ?string $actorId): void
    {
        if (str_contains($redirect['from'].$redirect['to'], '[') || RedirectStatus::from($redirect['status'])->sendsNowhere()) {
            return;
        }

        $into = DB::table('redirects')->where('kind', 'exact')->where('origin', 'import')
            ->where('target_kind', RedirectTarget::ADDRESS)
            ->where('target_path', PublicRequestPath::normalise($redirect['from']))
            ->get(['source', 'status']);

        foreach ($into as $other) {
            app(ImportRedirect::class)->execute(new ImportRedirectInput(
                from: '/'.$other->source,
                to: $redirect['to'],
                contentId: $redirect['contentId'],
                mediaId: $redirect['mediaId'],
                status: (int) $other->status,
                actorId: $actorId,
                overImport: true,
            ));
        }
    }
}
