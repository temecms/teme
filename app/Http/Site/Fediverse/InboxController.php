<?php

/**
 * The site's inboxes, shared and each actor's, new and imported: hands the
 * request to InboxGate and answers its status with no body.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\Inbox\InboxGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Teme\Contracts\Routes\PublicRequest;

final class InboxController extends Controller
{
    public function __invoke(Request $request, InboxGate $gate): Response
    {
        $public = self::publicRequest($request);

        if (! $gate->isInbox(RequestedAddress::of($public))) {
            return new Response('', 404);
        }

        $answer = $gate->receive($public);

        return new Response('', $answer->status, [...$answer->headers, 'Cache-Control' => 'no-store, private']);
    }

    private static function publicRequest(Request $request): PublicRequest
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $query = [];

        foreach ($request->query->all() as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return new PublicRequest('POST', $request->getPathInfo(), [], $query, $headers, self::body($request), (string) $request->ip());
    }

    /**
     * The body, read only when its declared length is within the limit, and
     * then never more than one byte past it, so InboxGate can answer 413.
     */
    private static function body(Request $request): string
    {
        $length = (string) $request->headers->get('content-length', '');

        if (ctype_digit($length) && (int) $length > InboxGate::MAX_BYTES) {
            return '';
        }

        $stream = $request->getContent(true);

        return (string) stream_get_contents($stream, InboxGate::MAX_BYTES + 1);
    }
}
