<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use App\Domain\Fediverse\Inbox\InboxGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Teme\Contracts\Routes\PublicRequest;

/**
 * The site's inboxes, shared and each actor's, new and imported: hands the
 * request to InboxGate and answers its status with no body.
 */
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

        return new PublicRequest('POST', $request->getPathInfo(), [], $query, $headers, $request->getContent(), (string) $request->ip());
    }
}
