<?php

/**
 * Serves one plugin stylesheet a manifest declares under contributes.styles, wrapped
 * in the teme.plugins layer. Anything else answers 404, and a sheet that can't be
 * served safely is recorded as the plugin's fault.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Styles\PluginStylesheet;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class PluginStylesheetController
{
    /**
     * The sheet, as text/css with a long cache life, since its address carries its content hash.
     */
    public function __invoke(string $code, string $file, PluginStylesheet $sheets, ListenerFaults $faults): Response
    {
        try {
            $sheet = $sheets->serve($code, $file);
        } catch (InvalidArgumentException $refused) {
            $faults->record($code, $code.'#styles', self::class, $refused);

            return response('', 404);
        }

        if ($sheet === null) {
            return response('', 404);
        }

        return response($sheet['css'], 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
