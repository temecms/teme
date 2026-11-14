<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Redirects\RedirectTarget;
use Illuminate\Support\Facades\DB;

/**
 * The address a trashed or unpublished item last had, for the offer to add
 * a redirect for it just after it is trashed (I-856).
 */
final readonly class TrashedAddress
{
    /**
     * Returns the address the item last had, as the site wrote it, and the
     * exact redirect from it if there is one; null while the item has a
     * current address, or never had one.
     *
     * @return array{address: string, redirectId: string|null}|null
     */
    public function of(string $contentId): ?array
    {
        if (Paths::current(RedirectTarget::CONTENT, $contentId) !== null) {
            return null;
        }

        $path = DB::table('paths')->where('target_type', RedirectTarget::CONTENT)->where('target_id', $contentId)
            ->orderByDesc('replaced_at')->orderByDesc('id')->value('path');

        if ($path === null) {
            return null;
        }

        $type = DB::table('contents')->where('id', $contentId)->value('type');
        $redirect = DB::table('redirects')->where('source', (string) $path)->value('id');

        return [
            'address' => Addresses::toUrl((string) $path, $type === null ? null : (string) $type),
            'redirectId' => $redirect === null ? null : (string) $redirect,
        ];
    }
}
