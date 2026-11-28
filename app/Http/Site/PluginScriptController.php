<?php

/**
 * Serves one plugin script a manifest declares under contributes.scripts, as text/javascript with
 * a long cache life, since its address carries its content hash. Anything else answers 404, and a
 * script that can't be served safely is recorded as the plugin's fault.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Scripts\PluginScriptFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class PluginScriptController
{
    /**
     * The script's text, as text/javascript, never sniffed, with a long cache life.
     */
    public function __invoke(Request $request, string $code, string $file, PluginScriptFile $scripts, ListenerFaults $faults): Response
    {
        // A script has one address: an encoded slash would name the same file as a plain one, so it is refused.
        if (str_contains(strtolower($request->getPathInfo()), '%2f')) {
            return response('', 404);
        }

        try {
            $served = $scripts->read($code, $file);
        } catch (InvalidArgumentException $refused) {
            $faults->record($code, $code.'#scripts', self::class, $refused);

            return response('', 404);
        }

        if ($served === null) {
            return response('', 404);
        }

        return response($served['text'], 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
