<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Site\SiteLanguage;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Illuminate\Support\Facades\Cache;

/**
 * Says while search is being rebuilt for a new site language, and how many
 * articles are still to go. It reads the count ReindexSearchJob keeps in the
 * cache, never counts over contents: every admin page asks every check.
 */
final readonly class SearchLanguageRebuilding implements StatusCheck
{
    public function __construct(private SiteLanguage $language) {}

    public function ability(): string
    {
        return 'settings.general.manage';
    }

    public function label(): string
    {
        return __('Search');
    }

    public function banner(): ?StatusBanner
    {
        $remaining = Cache::get(ReindexSearchJob::PROGRESS);

        if (! is_int($remaining) || $remaining <= 0) {
            return null;
        }

        return new StatusBanner('info', sprintf('Rebuilding search for %s: %s %s to go.', $this->language->label(), number_format($remaining), $remaining === 1 ? 'item' : 'items'), null, null, 10);
    }
}
