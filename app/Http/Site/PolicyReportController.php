<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Site\Policy\Reports\PolicyReportReader;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where browsers post the public site's policy reports (I-3375). Anyone may
 * send one, so it writes none of Teme's tables: what it can read it appends
 * to the spool, which RecordPolicyReports adds up a minute later, and it
 * answers 204 whatever happened, so a sender learns nothing.
 */
final class PolicyReportController extends Controller
{
    /** The most bytes of a report body that is read. */
    private const int MOST_BYTES = 16_384;

    public function __invoke(Request $request, PolicyReportSpool $spool): Response
    {
        if (PolicyMode::current() !== PolicyMode::Off) {
            $this->spool($request, $spool);
        }

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    /**
     * Reads the body, at most one byte more than the limit, from the stream
     * and not whole into memory, and spools each report in it. The first
     * read: bootstrap/app.php keeps the trimming middleware, which would
     * decode a whole JSON body, from this route.
     */
    private function spool(Request $request, PolicyReportSpool $spool): void
    {
        if ((int) $request->headers->get('Content-Length') > self::MOST_BYTES) {
            return;
        }

        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, self::MOST_BYTES + 1);

        if ($body === false || strlen($body) > self::MOST_BYTES) {
            return;
        }

        foreach (PolicyReportReader::read($request->headers->get('Content-Type'), $body) as $report) {
            $spool->append($report);
        }
    }
}
