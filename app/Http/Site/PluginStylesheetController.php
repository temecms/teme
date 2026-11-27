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
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class PluginStylesheetController
{
    /**
     * The sheet, as text/css with a long cache life, since its address carries its content hash.
     */
    public function __invoke(string $code, string $file, PluginStylesheet $sheets, ListenerFaults $faults): Response
    {
        $verdict = $sheets->verdict($code, $file);

        if ($verdict === null) {
            return response('', 404);
        }

        if ($verdict['css'] === null) {
            // Recorded once per refused content, so asking for the same sheet again adds no fault.
            if (Cache::add('plugin-styles-fault:'.($verdict['hash'] ?? $code.'/'.$file), true)) {
                $faults->record($verdict['name'], $code.'#styles', self::class, new InvalidArgumentException($verdict['refused'] ?? ''));
            }

            return response('', 404);
        }

        return response($verdict['css'], 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
