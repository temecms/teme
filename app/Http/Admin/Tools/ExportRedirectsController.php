<?php

/**
 * Every redirect as a download (I-872): a CSV file to edit and import
 * again, or nginx rules for a server in front of the site, streamed as
 * RedirectExport reads them, so a large site's never sit in memory.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Redirects\Queries\RedirectExport;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportRedirectsController extends Controller
{
    public function __invoke(RedirectExport $export, string $format): StreamedResponse
    {
        $csv = $format === 'csv';

        return response()->streamDownload(function () use ($export, $csv): void {
            if ($csv) {
                // A spreadsheet reads the file as UTF-8 only with its byte-order mark; the import takes it off.
                echo "\xEF\xBB\xBF";
            }

            foreach ($csv ? $export->csv() : $export->nginx() as $line) {
                echo $line;
            }
        }, $csv ? 'redirects.csv' : 'redirects-nginx.conf', ['Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8']);
    }
}
