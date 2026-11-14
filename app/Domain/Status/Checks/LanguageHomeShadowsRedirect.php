<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Illuminate\Support\Facades\DB;

/**
 * Tells whoever manages redirects when a redirect's source is a shown
 * language's prefix (I-3360): that language's home page answers the address
 * first, so the redirect never does. The one shadowing a prefix's refusals
 * can't prevent, since a redirect never holds an address of its own.
 */
final readonly class LanguageHomeShadowsRedirect implements StatusCheck
{
    /** As ApiAddressTaken's: something of the site that lost its address. */
    public const int PRIORITY = ApiAddressTaken::PRIORITY;

    public function ability(): string
    {
        return 'redirects.manage';
    }

    public function banner(): ?StatusBanner
    {
        $languages = app(SiteLanguages::class);
        $prefixes = [];

        // A hidden language's home answers 404, so its redirect still answers.
        foreach ($languages->shown() as $tag) {
            if ($tag !== $languages->defaultTag()) {
                $prefixes[$languages->prefix($tag)] = $tag;
            }
        }

        if ($prefixes === []) {
            return null;
        }

        // redirects.source holds a path without its slashes, as Paths does.
        $source = DB::table('redirects')->whereIn('source', array_map(strval(...), array_keys($prefixes)))->orderBy('source')->value('source');

        if ($source === null) {
            return null;
        }

        return new StatusBanner(
            'warning',
            __('The redirect from :address no longer answers: :language readers\' home page is there now.', ['address' => '/'.$source.'/', 'language' => $languages->label($prefixes[(string) $source])]),
            __('See the redirect'),
            route('tools.redirects.index', ['search' => '/'.$source], false),
            self::PRIORITY,
        );
    }
}
