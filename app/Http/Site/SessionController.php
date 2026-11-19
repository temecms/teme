<?php

/**
 * `/_teme/session`: who is reading a public page, for core's script (study
 * R §3.3, I-163): whether they are signed in, the "Edit" link for the item
 * the page shows when they may edit it, a CSRF token only for a browser that
 * needs one, and the name a signed-in person comments under (1.8b′), and the Comments
 * screen's address when they look after the item's comments (1.8c″). The
 * page itself stays the same for every reader, and is stored; only this
 * answer is personal, and nothing keeps it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\SignedInMarker;
use App\Domain\Site\Queries\EditLinks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class SessionController extends Controller
{
    public function __invoke(Request $request, EditLinks $links): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            // The marker outlived its session: forget it, so the browser is
            // given stored pages again and stops asking.
            Cookie::queue(SignedInMarker::expired());

            return self::answer(false, null, null);
        }

        $path = $request->query('path');

        // Laravel passes a same-origin POST that says so without a token
        // (PreventRequestForgery::hasValidOrigin()): a browser that sent
        // Sec-Fetch-Site here sends it with a form too, and needs none.
        $token = $request->header('Sec-Fetch-Site') === 'same-origin' ? null : $request->session()->token();

        $id = (string) $user->getAuthIdentifier();
        $address = is_string($path) ? $path : '/';

        return self::answer(true, $links->at($id, $address), $token, (string) $user->name, $links->looksAfterCommentsAt($id, $address) ? route('comments.index', [], false) : null);
    }

    /**
     * The answer, which no cache keeps.
     *
     * @param  array{href: string, label: string}|null  $edit
     */
    public static function answer(bool $signedIn, ?array $edit, ?string $token, ?string $name = null, ?string $moderate = null): JsonResponse
    {
        return new JsonResponse(['signedIn' => $signedIn, 'edit' => $edit, 'token' => $token, 'name' => $name, 'moderate' => $moderate], 200, ['Cache-Control' => 'no-store, private']);
    }
}
