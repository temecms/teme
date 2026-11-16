<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\SiteCode\SiteCodeSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * /ads.txt: the text saved under Site code, as plain UTF-8; a permanent
 * redirect to another host’s file when that mode is chosen; or 404 while
 * empty. The page cache stores either answer like robots.txt (I-064).
 */
final class AdsTxtController extends Controller
{
    public function __invoke(SettingsReader $settings): Response
    {
        $mode = $settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_MODE);

        if ($mode === SiteCodeSettings::MODE_REDIRECT) {
            $target = $settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT_REDIRECT);

            if (trim($target) === '') {
                abort(404);
            }

            return response('', 301, [
                'Location' => $target,
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $text = $settings->string(SiteCodeSettings::KEY, SiteCodeSettings::ADS_TXT);

        if (trim($text) === '') {
            abort(404);
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
